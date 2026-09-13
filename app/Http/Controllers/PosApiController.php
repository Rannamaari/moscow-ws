<?php

namespace App\Http\Controllers;

use App\Enums\SaleStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\TransactionException;
use App\Models\CashierShift;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\CustomerLedgerService;
use App\Services\InventoryQueryService;
use App\Services\NumberSequenceService;
use App\Services\ProductSearchService;
use App\Services\ReceiptProfileResolver;
use App\Services\SalesService;
use App\Support\PosUserContextResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosApiController extends Controller
{
    private const HELD_SALE_CANCELLATION_REASONS = [
        'Customer changed mind',
        'Customer did not have payment',
        'Duplicate / accidental cart',
        'Price enquiry only',
        'Item unavailable',
        'Manager instruction',
        'Other',
    ];

    public function __construct(
        private readonly ProductSearchService $productSearchService,
        private readonly InventoryQueryService $inventoryQueryService,
        private readonly SalesService $salesService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly NumberSequenceService $numberSequenceService,
        private readonly PosUserContextResolver $posUserContextResolver,
        private readonly ReceiptProfileResolver $receiptProfileResolver,
        private readonly CashierShiftService $cashierShiftService,
    ) {}

    public function csrfToken(Request $request): JsonResponse
    {
        return response()
            ->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'sales.create');
        $term = trim((string) $request->query('q', ''));

        $products = $this->productSearchService
            ->search($context['company_id'], $term)
            ->limit(15)
            ->get();

        return response()->json([
            'data' => $this->transformProductsForPos($context['company_id'], $context['branch_id'], $context['warehouse_id'], $products),
        ]);
    }

    public function barcodeLookup(Request $request, string $barcode): JsonResponse
    {
        $context = $this->posContext($request, 'sales.create');
        $product = $this->productSearchService->findByBarcode($context['company_id'], $barcode);

        if (! $product) {
            return response()->json([
                'message' => 'Barcode not found.',
                'errors' => [
                    'barcode' => ['No active product matched that barcode in this company.'],
                ],
            ], 404);
        }

        return response()->json([
            'data' => $this->transformProductForPos($context['company_id'], $context['branch_id'], $context['warehouse_id'], $product),
        ]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'customers.view');
        $term = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->where('company_id', $context['company_id'])
            ->where('is_active', true)
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(function (Builder $nested) use ($like): void {
                    $nested->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('tax_number', 'like', $like);
                });
            })
            ->orderByDesc('is_walk_in')
            ->orderBy('name')
            ->limit(15)
            ->get();

        return response()->json([
            'data' => $customers->map(fn (Customer $customer): array => $this->transformCustomer($customer, $context['branch']->currency))->all(),
        ]);
    }

    public function createCustomer(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'customers.create');

        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'in:7,15,30'],
        ])->validate();

        $customer = Customer::query()->create([
            'company_id' => $context['company_id'],
            'code' => $this->numberSequenceService->next($context['company_id'], 'customer'),
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'tax_number' => filled($validated['tax_number'] ?? null) ? trim($validated['tax_number']) : null,
            'payment_terms_days' => $validated['payment_terms_days'] ?? 7,
            'is_active' => true,
            'is_walk_in' => false,
        ]);

        return response()->json([
            'message' => 'Customer created.',
            'data' => $this->transformCustomer($customer, $context['branch']->currency),
        ], 201);
    }

    public function customerStatement(Request $request, Customer $customer): JsonResponse
    {
        $context = $this->posContext($request, 'customers.view');
        abort_unless($customer->company_id === $context['company_id'], 404);

        $currency = $context['branch']->currency;
        $salesQuery = Sale::query()
            ->reportable()
            ->where('company_id', $context['company_id'])
            ->where('customer_id', $customer->id)
            ->where('currency', $currency);
        $salesSummary = (clone $salesQuery)
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_invoiced, COALESCE(SUM(paid_total), 0) as total_paid')
            ->first();
        $sales = $salesQuery
            ->orderByDesc('sale_date')
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get();
        $payments = CustomerPayment::query()
            ->with('sale:id,sale_number')
            ->where('company_id', $context['company_id'])
            ->where('customer_id', $customer->id)
            ->where('currency', $currency)
            ->orderByDesc('paid_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'customer' => $this->transformCustomer($customer, $currency),
                'summary' => [
                    'total_invoiced' => number_format((float) ($salesSummary->total_invoiced ?? 0), 4, '.', ''),
                    'total_paid' => number_format((float) ($salesSummary->total_paid ?? 0), 4, '.', ''),
                    'outstanding_balance' => $this->customerLedgerService->currentBalance($customer->id, $currency),
                ],
                'invoices' => $sales->map(fn (Sale $sale): array => [
                    'id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'date' => $sale->sale_date?->toDateString(),
                    'grand_total' => (string) $sale->grand_total,
                    'paid_total' => (string) $sale->paid_total,
                    'balance_due' => (string) $sale->balance_due,
                    'due_date' => $sale->due_date?->toDateString(),
                    'payment_terms_days' => $sale->payment_terms_days,
                    'status' => $sale->status->value,
                    'customer' => $this->transformCustomer($customer, $currency),
                ])->all(),
                'payments' => $payments->map(fn (CustomerPayment $payment): array => [
                    'id' => $payment->id,
                    'sale_id' => $payment->sale_id,
                    'sale_number' => $payment->sale?->sale_number,
                    'paid_at' => $payment->paid_at?->timezone(config('app.business_timezone'))->toIso8601String(),
                    'payment_method' => $payment->payment_method,
                    'amount' => (string) $payment->amount,
                    'reference' => $payment->reference,
                    'notes' => $payment->notes,
                ])->all(),
            ],
        ]);
    }

    public function receiveCustomerPayment(Request $request, Sale $sale): JsonResponse
    {
        $context = $this->posContext($request, 'customers.payments');
        $this->ensureSaleInContext($sale, $context['company_id']);

        if (! $sale->customer_id || $sale->customer?->is_walk_in) {
            return response()->json(['message' => 'Payments can only be received against a regular customer credit bill.'], 422);
        }

        if (! in_array($sale->status, [SaleStatus::Completed, SaleStatus::PartiallyRefunded, SaleStatus::Refunded], true)) {
            return response()->json(['message' => 'Payments can only be received against a completed credit bill.'], 422);
        }

        if ((float) $sale->balance_due <= 0) {
            return response()->json(['message' => 'This bill has no outstanding balance.'], 422);
        }

        $validated = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,card,bank_transfer,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $shift = $this->requireActiveShift($context, $request);

        return $this->executeSaleMutation(function () use ($request, $context, $sale, $validated, $shift): Sale {
            $this->customerLedgerService->recordPayment(
                $context['company_id'],
                $sale->customer_id,
                $validated['amount'],
                $validated['payment_method'],
                [
                    'sale_id' => $sale->id,
                    'cashier_shift_id' => $shift->id,
                    'reference' => $validated['reference'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                    'paid_at' => now(),
                ],
            );

            return $sale->fresh(['items.product.primaryBarcode', 'payments', 'customer']);
        }, successMessage: 'Customer payment received.');
    }

    public function completeSale(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'sales.complete');

        return $this->executeSaleMutation(function () use ($request, $context): Sale {
            $validated = $this->validateSalePayload($request, $context, false, false);
            $shift = $this->requireActiveShift($context, $request);

            $sale = $this->salesService->createSale(
                $context['company_id'],
                $context['branch_id'],
                $context['warehouse_id'],
                $validated['items'],
                $validated['payments'],
                [
                    'customer_id' => $validated['customer_id'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                    'client_transaction_uuid' => $validated['client_transaction_uuid'],
                    'cashier_shift_id' => $shift?->id,
                ],
            );

            return $sale->fresh(['items.product.primaryBarcode', 'payments', 'customer']);
        });
    }

    public function holdSale(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'sales.hold');

        return $this->executeSaleMutation(function () use ($request, $context): Sale {
            $validated = $this->validateSalePayload($request, $context, true);
            $customer = $this->resolveHeldSaleCustomer($context['company_id'], $validated['customer_id'] ?? null);

            $sale = $this->salesService->createSale(
                $context['company_id'],
                $context['branch_id'],
                $context['warehouse_id'],
                $validated['items'],
                [],
                [
                    'customer_id' => $customer->id,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                    'client_transaction_uuid' => $validated['client_transaction_uuid'],
                    'status' => SaleStatus::Held,
                ],
            );

            return $sale->fresh(['items.product.primaryBarcode', 'customer']);
        });
    }

    public function reholdSale(Request $request, Sale $sale): JsonResponse
    {
        $context = $this->posContext($request, 'sales.hold');
        $this->ensureSaleInContext($sale, $context['company_id']);
        $this->ensureHeldSaleAccessible($sale, $request, allowCancelled: false);

        return $this->executeSaleMutation(function () use ($request, $context, $sale): Sale {
            $validated = $this->validateSalePayload($request, $context, true);
            $customer = $this->resolveHeldSaleCustomer($context['company_id'], $validated['customer_id'] ?? null);

            return $this->salesService->updateHeldSale(
                $sale->id,
                $context['company_id'],
                $context['branch_id'],
                $context['warehouse_id'],
                $validated['items'],
                [
                    'customer_id' => $customer->id,
                    'notes' => $validated['notes'] ?? null,
                ],
            )->fresh(['items.product.primaryBarcode', 'customer']);
        }, successMessage: 'Sale held.');
    }

    public function heldSales(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->can('sales.resume') || $user->can('sales.hold')), 403);
        $context = $this->posUserContextResolver->resolve($user);

        $heldSales = Sale::query()
            ->with(['customer', 'items', 'company', 'branch', 'warehouse'])
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('warehouse_id', $context['warehouse_id'])
            ->where('status', SaleStatus::Held)
            ->when(! $request->user()->can('sales.cancel_held'), function (Builder $query) use ($request): void {
                $query->where('created_by', $request->user()->id);
            })
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $heldSales->map(fn (Sale $sale): array => $this->transformHeldSale($sale))->all(),
        ]);
    }

    public function resumeHeldSale(Request $request, Sale $sale): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->can('sales.resume') || $user->can('sales.hold')), 403);

        $context = $this->posUserContextResolver->resolve($user);
        $this->ensureSaleInContext($sale, $context['company_id']);
        $this->ensureHeldSaleAccessible($sale, $request, allowCancelled: false);

        if ($sale->status !== SaleStatus::Held) {
            return response()->json([
                'message' => 'Only held sales can be resumed.',
            ], 422);
        }

        $sale->load(['items.product.primaryBarcode', 'items.product.unit', 'customer', 'company', 'branch', 'warehouse', 'creator']);

        return response()->json([
            'data' => $this->transformSale($sale),
        ]);
    }

    public function listSales(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'sales.view');
        $filters = $this->validateSalesHistoryFilters($request);

        abort_unless($filters['status'] !== SaleStatus::Cancelled->value || $request->user()->can('sales.view_cancelled'), 403);

        $sales = $this->buildSalesHistoryQuery($context['company_id'], $filters, $request->user())
            ->paginate($filters['per_page'])
            ->withQueryString();

        return response()->json([
            'data' => collect($sales->items())->map(fn (Sale $sale): array => $this->transformSaleHistoryRow($sale))->all(),
            'meta' => $this->transformPaginator($sales),
            'filters' => $this->presentAppliedSalesFilters($filters),
        ]);
    }

    public function showSale(Request $request, Sale $sale): JsonResponse
    {
        $context = $this->posContext($request, 'sales.view');
        $this->ensureSaleInContext($sale, $context['company_id']);
        $this->ensureHeldSaleAccessible($sale, $request);
        $this->ensureCancelledSaleVisible($sale, $request);

        $sale->load(['items.product.primaryBarcode', 'items.product.unit', 'payments', 'customer', 'company', 'branch', 'warehouse', 'creator', 'canceller', 'returns.items']);

        return response()->json([
            'data' => $this->transformSale($sale),
        ]);
    }

    public function completeHeldSale(Request $request, Sale $sale): JsonResponse
    {
        $context = $this->posContext($request, 'sales.complete');
        $this->ensureSaleInContext($sale, $context['company_id']);
        $this->ensureHeldSaleAccessible($sale, $request, allowCancelled: false);

        if ($sale->status !== SaleStatus::Held) {
            return response()->json([
                'message' => 'Only held sales can be completed from this endpoint.',
            ], 422);
        }

        return $this->executeSaleMutation(function () use ($request, $sale): Sale {
            $context = $this->posContext($request, 'sales.complete');
            $validated = $this->validateSalePayload($request, $context, false, false);
            $shift = $this->requireActiveShift($context, $request);

            $completed = $this->salesService->completeHeldSale(
                $sale->id,
                $context['company_id'],
                $context['branch_id'],
                $context['warehouse_id'],
                $validated['items'],
                $validated['payments'],
                [
                    'customer_id' => $validated['customer_id'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $request->user()->id,
                    'completed_at' => now(),
                    'cashier_shift_id' => $shift?->id,
                ],
            );

            return $completed->fresh(['items.product.primaryBarcode', 'payments', 'customer']);
        });
    }

    public function openShift(Request $request): JsonResponse
    {
        $context = $this->posContext($request, 'sales.create');
        $validated = Validator::make($request->all(), [
            'opening_cash' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $shift = $this->cashierShiftService->open(
            $context,
            $request->user(),
            (float) $validated['opening_cash'],
            $validated['notes'] ?? null,
        );

        return response()->json(['message' => 'Cashier shift opened.', 'data' => $this->transformShift($shift)]);
    }

    public function closeShift(Request $request, CashierShift $cashierShift): JsonResponse
    {
        $context = $this->posContext($request, 'sales.create');
        $validated = Validator::make($request->all(), [
            'closing_cash' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $shift = $this->cashierShiftService->close(
                $cashierShift,
                $context,
                $request->user(),
                (float) $validated['closing_cash'],
                $validated['notes'] ?? null,
            );
        } catch (TransactionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Shift closed and EOD report generated.',
            'data' => $this->transformShift($shift),
            'print_url' => route('cashier-shifts.print', $shift),
        ]);
    }

    public function cancelHeldSale(Request $request, Sale $sale): JsonResponse
    {
        $context = $this->posContext($request, 'sales.cancel_held');
        $this->ensureSaleInContext($sale, $context['company_id']);
        $this->ensureHeldSaleAccessible($sale, $request, allowCancelled: false);

        $validated = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'in:'.implode(',', self::HELD_SALE_CANCELLATION_REASONS)],
            'notes' => ['nullable', 'string'],
        ])->after(function ($validator) use ($request): void {
            if ($request->input('reason') === 'Other' && trim((string) $request->input('notes', '')) === '') {
                $validator->errors()->add('notes', 'Notes are required when the cancellation reason is Other.');
            }
        })->validate();

        $cancelled = $this->salesService->cancelHeldSale(
            $sale->id,
            $context['company_id'],
            $validated['reason'],
            $validated['notes'] ?? null,
            $request->user()->id,
        );

        return response()->json([
            'message' => 'Held sale cancelled.',
            'data' => $this->transformSale($cancelled->fresh(['items.product.primaryBarcode', 'customer', 'canceller'])),
        ]);
    }

    public function searchSales(Request $request): JsonResponse
    {
        return $this->listSales($request);
    }

    public function returnSale(Request $request, Sale $sale): JsonResponse
    {
        $context = $this->posContext($request, 'sales.return');
        $this->ensureSaleInContext($sale, $context['company_id']);

        return $this->executeSaleMutation(function () use ($request, $sale): Sale {
            $validated = Validator::make($request->all(), [
                'items' => ['required', 'array', 'min:1'],
                'items.*.sale_item_id' => ['required', 'string'],
                'items.*.quantity' => ['required', 'numeric', 'gt:0'],
                'notes' => ['nullable', 'string'],
            ])->validate();

            $quantities = collect($validated['items'])->mapWithKeys(fn (array $item): array => [
                $item['sale_item_id'] => $item['quantity'],
            ])->all();

            $saleReturn = $this->salesService->returnSale($sale->id, $quantities, [
                'created_by' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            return $saleReturn->sale->fresh(['items.product.primaryBarcode', 'returns.items', 'customer']);
        }, successMessage: 'Sale return processed.');
    }

    private function executeSaleMutation(callable $callback, string $successMessage = 'Sale processed.'): JsonResponse
    {
        try {
            /** @var Sale $sale */
            $sale = $callback();

            return response()->json([
                'message' => $successMessage,
                'data' => $this->transformSale($sale),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (InventoryException $exception) {
            return response()->json([
                'message' => 'Inventory validation failed.',
                'errors' => [
                    'inventory' => [$exception->getMessage()],
                ],
            ], 422);
        } catch (TransactionException $exception) {
            return response()->json([
                'message' => 'Sale validation failed.',
                'errors' => [
                    'sale' => [$exception->getMessage()],
                ],
            ], 422);
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, payments: array<int, array<string, mixed>>, client_transaction_uuid: string, customer_id?: string, notes?: string}
     */
    private function validateSalePayload(Request $request, array $context, bool $allowNoPayments, bool $requireClientTransactionUuid = true): array
    {
        $rules = [
            'customer_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'client_transaction_uuid' => [$requireClientTransactionUuid ? 'required' : 'nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'gte:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'payments' => [$allowNoPayments ? 'nullable' : 'present', 'array'],
            'payments.*.payment_method' => ['required_with:payments', 'string', 'max:255'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'gt:0'],
            'payments.*.amount_tendered' => ['nullable', 'numeric', 'gte:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.notes' => ['nullable', 'string'],
        ];

        $validated = Validator::make($request->all(), $rules)->validate();

        $items = collect($validated['items'])->map(function (array $item) use ($context): array {
            $product = Product::query()
                ->with('unit')
                ->where('company_id', $context['company_id'])
                ->find($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ['One or more products do not belong to this company.'],
                ]);
            }

            $precision = $product->unit?->precision ?? 0;
            $quantity = round((float) $item['quantity'], $precision);

            if ($precision === 0 && floor($quantity) !== $quantity) {
                throw ValidationException::withMessages([
                    'items' => ["{$product->name} requires whole-number quantities."],
                ]);
            }

            return [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => array_key_exists('unit_price', $item) ? round((float) $item['unit_price'], 4) : null,
                'discount_amount' => array_key_exists('discount_amount', $item) ? round((float) $item['discount_amount'], 4) : null,
            ];
        })->all();

        $this->assertFrontendPermissions($request, $items);

        if (! $allowNoPayments) {
            $this->assertCreditPermission($request, $this->estimateGrandTotal($context['company_id'], $items), $validated['payments'] ?? []);
            $this->checkStructuredStock($context['company_id'], $context['warehouse_id'], $items);
        }

        $validated['items'] = $items;
        $validated['payments'] = $validated['payments'] ?? [];

        return $validated;
    }

    private function resolveHeldSaleCustomer(string $companyId, ?string $customerId): Customer
    {
        if (! $customerId) {
            throw ValidationException::withMessages([
                'customer_id' => ['Select a saved customer before holding a sale.'],
            ]);
        }

        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->find($customerId);

        if (! $customer || $customer->is_walk_in) {
            throw ValidationException::withMessages([
                'customer_id' => ['Select a saved customer before holding a sale.'],
            ]);
        }

        return $customer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function checkStructuredStock(string $companyId, string $warehouseId, array $items): void
    {
        if (Company::query()->whereKey($companyId)->value('pos_test_mode')) {
            return;
        }

        $errors = [];

        foreach ($items as $item) {
            $product = Product::query()
                ->with('unit')
                ->where('company_id', $companyId)
                ->findOrFail($item['product_id']);

            if (! $product->track_inventory || $product->allow_negative_stock) {
                continue;
            }

            $available = (float) $this->inventoryQueryService->getBalance($companyId, $warehouseId, $product->id);
            $requested = (float) $item['quantity'];

            if ($requested > $available + 0.0001) {
                $errors[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'requested' => number_format($requested, 4, '.', ''),
                    'available' => number_format($available, 4, '.', ''),
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'stock' => ['Insufficient stock for one or more items.'],
                'stock_details' => $errors,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertFrontendPermissions(Request $request, array $items): void
    {
        foreach ($items as $item) {
            if (($item['unit_price'] ?? null) !== null && isset($item['product_id'])) {
                $product = Product::query()->find($item['product_id']);

                $context = $this->posContext($request, 'sales.create');
                $storePrice = $product?->branchPrices()->where('branch_id', $context['branch_id'])->value('selling_price');

                if ($product && round((float) $item['unit_price'], 4) !== round((float) ($storePrice ?? $product->selling_price), 4) && ! $request->user()->can('sales.price_override')) {
                    throw ValidationException::withMessages([
                        'price_override' => ['Price override requires permission.'],
                    ]);
                }
            }

            if (($item['discount_amount'] ?? null) !== null && (float) $item['discount_amount'] > 0 && ! $request->user()->can('sales.discount')) {
                throw ValidationException::withMessages([
                    'discount' => ['Discount requires permission.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function estimateGrandTotal(string $companyId, array $items): float
    {
        $productIds = collect($items)->pluck('product_id')->filter()->values();
        $products = Product::query()
            ->with('company:id,default_tax_rate')
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        return round(collect($items)->sum(function (array $item) use ($products): float {
            $product = $products->get($item['product_id']);

            if (! $product) {
                return 0.0;
            }

            $quantity = (float) $item['quantity'];
            $unitPrice = array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                ? (float) $item['unit_price']
                : (float) $product->selling_price;
            $discountAmount = (float) ($item['discount_amount'] ?? 0);
            $lineSubtotal = round($quantity * $unitPrice, 4);
            $taxBase = round($lineSubtotal - $discountAmount, 4);
            $taxAmount = round($taxBase * ($product->effectiveTaxRate() / 100), 4);

            return round($taxBase + $taxAmount, 4);
        }), 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function assertCreditPermission(Request $request, float|string $grandTotal, array $payments): void
    {
        $paidTotal = round(collect($payments)->sum(fn (array $payment): float => (float) ($payment['amount'] ?? 0)), 4);
        $balanceDue = round((float) $grandTotal - $paidTotal, 4);

        if ($balanceDue > 0.0001 && ! $request->user()->can('sales.credit')) {
            throw ValidationException::withMessages([
                'credit' => ['Credit sale permission is required when paid amount is less than the total.'],
            ]);
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function transformProductsForPos(string $companyId, string $branchId, string $warehouseId, $products): array
    {
        $balances = $this->inventoryQueryService->getBalancesForProducts($companyId, $warehouseId, $products->pluck('id')->all());

        return $products->map(function (Product $product) use ($balances, $branchId): array {
            return $this->transformProductForPosFromBalance($product, $balances[$product->id] ?? '0.0000', $branchId);
        })->all();
    }

    private function transformProductForPos(string $companyId, string $branchId, string $warehouseId, Product $product): array
    {
        $balance = $product->track_inventory
            ? $this->inventoryQueryService->getBalance($companyId, $warehouseId, $product->id)
            : null;

        return $this->transformProductForPosFromBalance($product, $balance, $branchId);
    }

    /** @return array<string, mixed> */
    private function transformShift(CashierShift $shift): array
    {
        return [
            'id' => $shift->id,
            'shift_number' => $shift->shift_number,
            'status' => $shift->status,
            'currency' => $shift->currency,
            'opening_cash' => $shift->opening_cash,
            'expected_cash' => $shift->expected_cash,
            'closing_cash' => $shift->closing_cash,
            'cash_variance' => $shift->cash_variance,
            'opened_at' => $shift->opened_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            'closed_at' => $shift->closed_at?->timezone(config('app.business_timezone'))->toIso8601String(),
        ];
    }

    private function requireActiveShift(array $context, Request $request): CashierShift
    {
        $shift = $this->cashierShiftService->activeFor($context, $request->user()->id);

        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => ['Open a cashier shift before completing a sale.'],
            ]);
        }

        return $shift;
    }

    private function transformProductForPosFromBalance(Product $product, ?string $balance, string $branchId): array
    {
        $product->loadMissing(['unit', 'primaryBarcode', 'company:id,default_tax_rate']);
        $price = $product->branchPrices()->where('branch_id', $branchId)->first();

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'barcode' => $product->primaryBarcode?->barcode,
            'price' => (string) ($price?->selling_price ?? $product->selling_price),
            'cost_price' => (string) ($price?->cost_price ?? $product->cost_price),
            'tax_rate' => number_format($product->effectiveTaxRate(), 4, '.', ''),
            'stock' => $product->track_inventory ? $balance : null,
            'stock_label' => $product->track_inventory ? $balance : 'Non-stock',
            'track_inventory' => $product->track_inventory,
            'unit' => [
                'name' => $product->unit?->name,
                'short_name' => $product->unit?->short_name,
                'precision' => $product->unit?->precision ?? 0,
            ],
        ];
    }

    private function transformCustomer(Customer $customer, ?string $currency = null): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'tax_number' => $customer->tax_number,
            'payment_terms_days' => $customer->payment_terms_days ?? 7,
            'balance' => $this->customerLedgerService->currentBalance($customer->id, $currency),
            'credit_limit' => $customer->credit_limit,
            'is_walk_in' => $customer->is_walk_in,
        ];
    }

    private function transformHeldSale(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'currency' => $sale->currency,
            'customer' => $sale->customer?->name ?? 'Walk-in Customer',
            'amount' => (string) $sale->grand_total,
            'created_at' => $sale->created_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            'cashier' => $sale->creator?->name,
            'item_count' => $sale->items->count(),
        ];
    }

    private function transformSaleHistoryRow(Sale $sale): array
    {
        $sale->loadMissing(['customer', 'branch', 'creator', 'payments']);

        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'date' => $sale->cancelled_at?->timezone(config('app.business_timezone'))->toIso8601String() ?? $sale->completed_at?->timezone(config('app.business_timezone'))->toIso8601String() ?? $sale->created_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            'customer' => $sale->customer?->name ?? 'Walk-in Customer',
            'customer_phone' => $sale->customer?->phone,
            'branch' => $sale->branch?->name,
            'cashier' => $sale->creator?->name,
            'payment_method' => $sale->payments->pluck('payment_method')->filter()->unique()->values()->implode(', '),
            'total' => (string) $sale->grand_total,
            'paid' => (string) $sale->paid_total,
            'balance' => (string) $sale->balance_due,
            'status' => $sale->status->value,
        ];
    }

    private function transformSale(Sale $sale): array
    {
        $sale->loadMissing(['items.product.primaryBarcode', 'items.product.unit', 'payments', 'customer', 'returns.items', 'company', 'branch', 'warehouse', 'creator', 'canceller']);
        $receipt = $sale->receipt_snapshot ?: $this->receiptProfileResolver->resolve($sale->company, $sale->branch);
        $returnedQuantities = SaleReturnItem::query()
            ->selectRaw('sale_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
            ->whereIn('sale_item_id', $sale->items->pluck('id'))
            ->groupBy('sale_item_id')
            ->pluck('returned_quantity', 'sale_item_id');

        return [
            'id' => $sale->id,
            'sale_number' => $sale->sale_number,
            'status' => $sale->status->value,
            'sale_date' => $sale->sale_date?->toDateString(),
            'completed_at' => $sale->completed_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            'created_at' => $sale->created_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            'company' => [
                'id' => $sale->company?->id,
                'name' => $sale->company?->name,
                'currency' => $sale->currency,
                'tax_number' => $sale->company?->tax_number,
                'address' => $sale->company?->address,
                'city' => $sale->company?->city,
                'country' => $sale->company?->country,
                'phone' => $sale->company?->phone,
                'receipt_shop_name' => $sale->company?->receipt_shop_name,
                'receipt_gst_label' => $sale->company?->receipt_gst_label ?? 'GST No.',
                'receipt_header' => $sale->company?->receipt_header,
                'receipt_footer' => $sale->company?->receipt_footer,
                'receipt_show_address' => $sale->company?->receipt_show_address ?? true,
                'receipt_show_phone' => $sale->company?->receipt_show_phone ?? true,
            ],
            'receipt' => $receipt,
            'branch' => [
                'id' => $sale->branch?->id,
                'name' => $sale->branch?->name,
                'code' => $sale->branch?->code,
            ],
            'warehouse' => [
                'id' => $sale->warehouse?->id,
                'name' => $sale->warehouse?->name,
                'code' => $sale->warehouse?->code,
            ],
            'cashier' => [
                'id' => $sale->creator?->id,
                'name' => $sale->creator?->name,
            ],
            'customer' => $sale->customer ? $this->transformCustomer($sale->customer, $sale->currency) : null,
            'subtotal' => (string) $sale->subtotal,
            'discount_total' => (string) $sale->discount_total,
            'tax_total' => (string) $sale->tax_total,
            'grand_total' => (string) $sale->grand_total,
            'paid_total' => (string) $sale->paid_total,
            'balance_due' => (string) $sale->balance_due,
            'customer_tax_number' => $sale->customer_tax_number,
            'payment_terms_days' => $sale->payment_terms_days,
            'due_date' => $sale->due_date?->toDateString(),
            'cancellation_reason' => $sale->cancellation_reason,
            'cancellation_notes' => $sale->cancellation_notes,
            'cancelled_at' => $sale->cancelled_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            'cancelled_by' => $sale->canceller ? [
                'id' => $sale->canceller->id,
                'name' => $sale->canceller->name,
            ] : null,
            'payment_method_summary' => $sale->payments->pluck('payment_method')->filter()->unique()->values()->implode(', '),
            'items' => $sale->items->map(function (SaleItem $item) use ($returnedQuantities): array {
                $returned = (float) ($returnedQuantities[$item->id] ?? 0);

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->description,
                    'sku' => $item->product?->sku,
                    'barcode' => $item->product?->primaryBarcode?->barcode,
                    'quantity' => (string) $item->quantity,
                    'returned_quantity' => number_format($returned, 4, '.', ''),
                    'returnable_quantity' => number_format(max(0, (float) $item->quantity - $returned), 4, '.', ''),
                    'unit_price' => (string) $item->unit_price,
                    'unit_cost' => (string) $item->unit_cost,
                    'discount_amount' => (string) $item->discount_amount,
                    'tax_rate' => (string) $item->tax_rate,
                    'tax_amount' => (string) $item->tax_amount,
                    'line_total' => (string) $item->line_total,
                    'track_inventory' => $item->product?->track_inventory ?? true,
                    'unit' => [
                        'name' => $item->product?->unit?->name,
                        'short_name' => $item->product?->unit?->short_name,
                        'precision' => $item->product?->unit?->precision ?? 0,
                    ],
                ];
            })->all(),
            'payments' => $sale->payments->map(fn ($payment): array => [
                'id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'amount' => (string) $payment->amount,
                'amount_tendered' => $payment->amount_tendered !== null ? (string) $payment->amount_tendered : null,
                'change_due' => (string) $payment->change_due,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->timezone(config('app.business_timezone'))->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array{
     *     search:string,
     *     date_from:string,
     *     date_to:string,
     *     cashier:?string,
     *     customer:?string,
     *     status:?string,
     *     payment_method:?string,
     *     period:string,
     *     page:int,
     *     per_page:int
     * }
     */
    private function validateSalesHistoryFilters(Request $request): array
    {
        $validated = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'cashier' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:completed,refunded,partially_refunded,cancelled'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'period' => ['nullable', 'in:today,yesterday,last_7_days,custom'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ])->validate();

        $search = trim((string) ($validated['search'] ?? $validated['q'] ?? ''));
        $period = $validated['period'] ?? null;
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;

        if ($period === null) {
            $period = ($dateFrom || $dateTo) ? 'custom' : 'today';
        }

        [$resolvedFrom, $resolvedTo] = $this->resolveSalesHistoryPeriod($period, $dateFrom, $dateTo);

        return [
            'search' => $search,
            'date_from' => $resolvedFrom,
            'date_to' => $resolvedTo,
            'cashier' => isset($validated['cashier']) ? trim((string) $validated['cashier']) : null,
            'customer' => isset($validated['customer']) ? trim((string) $validated['customer']) : null,
            'status' => isset($validated['status']) && $validated['status'] !== '' ? $validated['status'] : null,
            'payment_method' => isset($validated['payment_method']) && $validated['payment_method'] !== '' ? $validated['payment_method'] : null,
            'period' => $period,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 25),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildSalesHistoryQuery(string $companyId, array $filters, User $user): Builder
    {
        $search = $filters['search'];
        $customerFilter = $filters['customer'];
        $cashierFilter = $filters['cashier'];
        $statusFilter = $filters['status'];
        $paymentMethodFilter = $filters['payment_method'];

        return Sale::query()
            ->with([
                'customer:id,name,phone',
                'creator:id,name,email',
                'payments:id,sale_id,payment_method',
            ])
            ->where('company_id', $companyId)
            // Keep cashier history private while preserving manager and administrator oversight.
            ->when(! $user->can('sales.cancel_held'), function (Builder $query) use ($user): void {
                $query->where('created_by', $user->id);
            })
            ->when($statusFilter, function (Builder $query) use ($statusFilter): void {
                $query->where('status', $statusFilter);
            }, function (Builder $query): void {
                $query->whereIn('status', [
                    SaleStatus::Completed->value,
                    SaleStatus::Refunded->value,
                    SaleStatus::PartiallyRefunded->value,
                ]);
            })
            ->whereDate('sale_date', '>=', $filters['date_from'])
            ->whereDate('sale_date', '<=', $filters['date_to'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(function (Builder $nested) use ($like): void {
                    $nested->where('sale_number', 'like', $like)
                        ->orWhereHas('customer', function (Builder $customerQuery) use ($like): void {
                            $customerQuery->where('name', 'like', $like)
                                ->orWhere('phone', 'like', $like);
                        });
                });
            })
            ->when($customerFilter, function (Builder $query) use ($customerFilter): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $customerFilter).'%';

                $query->whereHas('customer', function (Builder $customerQuery) use ($like): void {
                    $customerQuery->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('code', 'like', $like);
                });
            })
            ->when($cashierFilter, function (Builder $query) use ($cashierFilter): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $cashierFilter).'%';

                $query->whereHas('creator', function (Builder $cashierQuery) use ($like): void {
                    $cashierQuery->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($paymentMethodFilter, function (Builder $query) use ($paymentMethodFilter): void {
                $query->whereHas('payments', function (Builder $paymentQuery) use ($paymentMethodFilter): void {
                    $paymentQuery->where('payment_method', $paymentMethodFilter);
                });
            })
            ->orderByDesc('sale_date')
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at');
    }

    private function ensureHeldSaleAccessible(Sale $sale, Request $request, bool $allowCancelled = true): void
    {
        if ($sale->status === SaleStatus::Held && ! $request->user()->can('sales.cancel_held') && $sale->created_by !== $request->user()->id) {
            abort(404);
        }

        if (! $allowCancelled && $sale->status === SaleStatus::Cancelled) {
            abort(422, 'Cancelled held sales cannot be resumed or completed.');
        }
    }

    private function ensureCancelledSaleVisible(Sale $sale, Request $request): void
    {
        if ($sale->status === SaleStatus::Cancelled && ! $request->user()->can('sales.view_cancelled')) {
            abort(404);
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveSalesHistoryPeriod(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        $today = Carbon::today();

        return match ($period) {
            'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            'last_7_days' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
            'custom' => [
                Carbon::parse($dateFrom ?? $today->toDateString())->toDateString(),
                Carbon::parse($dateTo ?? $dateFrom ?? $today->toDateString())->toDateString(),
            ],
            default => [$today->toDateString(), $today->toDateString()],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function presentAppliedSalesFilters(array $filters): array
    {
        return [
            'search' => $filters['search'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'cashier' => $filters['cashier'],
            'customer' => $filters['customer'],
            'status' => $filters['status'],
            'payment_method' => $filters['payment_method'],
            'period' => $filters['period'],
            'per_page' => $filters['per_page'],
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function transformPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * @return array{company_id:string, branch_id:string, warehouse_id:string}
     */
    private function posContext(Request $request, string $permission): array
    {
        $user = $request->user();

        abort_unless($user && $user->can($permission), 403);

        return $this->posUserContextResolver->resolve($user);
    }

    private function ensureSaleInContext(Sale $sale, string $companyId): void
    {
        abort_unless($sale->company_id === $companyId, 404);
    }
}
