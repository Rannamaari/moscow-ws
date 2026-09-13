<?php

namespace App\Services;

use App\Enums\CustomerTransactionType;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\TaxCategory;
use App\Exceptions\TransactionException;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Product;
use App\Models\ProductBranchPrice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly NumberSequenceService $numberSequenceService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly ReceiptProfileResolver $receiptProfileResolver,
        private readonly TelegramSalesBotService $telegramSalesBot,
    ) {}

    public function createSale(
        string $companyId,
        string $branchId,
        string $warehouseId,
        array $items,
        array $payments = [],
        array $attributes = [],
    ): Sale {
        if ($clientUuid = $attributes['client_transaction_uuid'] ?? null) {
            $existing = Sale::query()
                ->where('company_id', $companyId)
                ->where('client_transaction_uuid', $clientUuid)
                ->first();

            if ($existing) {
                return $existing->load('items', 'payments');
            }
        }

        return DB::transaction(function () use ($companyId, $branchId, $warehouseId, $items, $payments, $attributes): Sale {
            $warehouse = $this->resolveWarehouse($companyId, $branchId, $warehouseId);
            $branch = Branch::query()->where('company_id', $companyId)->findOrFail($branchId);

            if ($shiftId = $attributes['cashier_shift_id'] ?? null) {
                $shift = CashierShift::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('warehouse_id', $warehouse->id)
                    ->lockForUpdate()
                    ->findOrFail($shiftId);

                if ($shift->status !== 'open') {
                    throw new TransactionException('The cashier shift is already closed. Open a new shift before completing this sale.');
                }
            }

            $customer = $this->resolveCustomer($companyId, $attributes['customer_id'] ?? null);
            $status = $attributes['status'] ?? SaleStatus::Completed;

            if (! $status instanceof SaleStatus) {
                $status = SaleStatus::from($status);
            }

            if ($items === []) {
                throw new TransactionException('At least one sale item is required.');
            }

            $lineItems = $this->prepareSaleItems($companyId, $branchId, $items);
            $totals = $this->calculateTotals(
                $lineItems->sum('line_subtotal'),
                $lineItems->sum('discount_amount'),
                $lineItems->sum('tax_amount'),
                $attributes['delivery_charge'] ?? 0,
            );

            $sale = Sale::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouse->id,
                'cashier_shift_id' => $attributes['cashier_shift_id'] ?? null,
                'customer_id' => $customer?->id,
                'customer_tax_number' => $customer?->tax_number,
                'payment_terms_days' => $customer && ! $customer->is_walk_in ? ($customer->payment_terms_days ?? 7) : null,
                'sale_number' => $attributes['sale_number'] ?? $this->numberSequenceService->next($companyId, 'sale'),
                'status' => $status,
                'currency' => $branch->currency,
                'client_transaction_uuid' => $attributes['client_transaction_uuid'] ?? null,
                'sale_date' => $attributes['sale_date'] ?? Carbon::now(config('app.business_timezone'))->toDateString(),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => $this->formatDecimal(0),
                'balance_due' => $totals['grand_total'],
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
                'completed_at' => $status === SaleStatus::Completed ? ($attributes['completed_at'] ?? now()) : null,
                'sales_channel' => $attributes['sales_channel'] ?? 'pos',
                'order_status' => $attributes['order_status'] ?? null,
                'payment_status' => $attributes['payment_status'] ?? null,
                'website_payment_method' => $attributes['website_payment_method'] ?? null,
                'delivery_method' => $attributes['delivery_method'] ?? null,
                'delivery_charge' => $attributes['delivery_charge'] ?? 0,
                'delivery_address' => $attributes['delivery_address'] ?? null,
                'tracking_token' => $attributes['tracking_token'] ?? null,
            ]);

            foreach ($lineItems as $lineItem) {
                SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'company_id' => $companyId,
                    'product_id' => $lineItem['product']->id,
                    'description' => $lineItem['description'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['unit_price'],
                    'unit_cost' => $lineItem['unit_cost'],
                    'discount_amount' => $lineItem['discount_amount'],
                    'tax_rate' => $lineItem['tax_rate'],
                    'tax_amount' => $lineItem['tax_amount'],
                    'tax_category' => $lineItem['tax_category'],
                    'line_total' => $lineItem['line_total'],
                ]);
            }

            if ($status === SaleStatus::Completed) {
                $this->finalizeSale($sale, $payments, $customer, $attributes);
                $this->notifySaleAfterCommit($sale);
            }

            return $sale->fresh('items', 'payments');
        });
    }

    public function completeSale(string $saleId, array $payments = [], ?string $completedBy = null): Sale
    {
        return DB::transaction(function () use ($saleId, $payments, $completedBy): Sale {
            $sale = Sale::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($saleId);

            if ($sale->status === SaleStatus::Completed || $sale->status === SaleStatus::Refunded || $sale->status === SaleStatus::PartiallyRefunded) {
                return $sale->load('items', 'payments');
            }

            if (in_array($sale->status, [SaleStatus::Voided, SaleStatus::Cancelled], true)) {
                throw new TransactionException('Cancelled sales cannot be completed.');
            }

            $customer = $this->resolveCustomer($sale->company_id, $sale->customer_id);

            $this->finalizeSale($sale, $payments, $customer, [
                'created_by' => $completedBy,
                'completed_at' => now(),
            ]);
            $this->notifySaleAfterCommit($sale);

            return $sale->fresh('items', 'payments');
        });
    }

    public function updateHeldSale(
        string $saleId,
        string $companyId,
        string $branchId,
        string $warehouseId,
        array $items,
        array $attributes = [],
    ): Sale {
        return DB::transaction(function () use ($saleId, $companyId, $branchId, $warehouseId, $items, $attributes): Sale {
            $sale = Sale::query()
                ->lockForUpdate()
                ->with('payments')
                ->where('company_id', $companyId)
                ->findOrFail($saleId);

            if ($sale->status !== SaleStatus::Held) {
                throw new TransactionException('Only held sales can be updated.');
            }

            if ($sale->payments->isNotEmpty()) {
                throw new TransactionException('Held sales cannot contain payments.');
            }

            $warehouse = $this->resolveWarehouse($companyId, $branchId, $warehouseId);
            $branch = Branch::query()->where('company_id', $companyId)->findOrFail($branchId);
            $customer = $this->resolveCustomer($companyId, $attributes['customer_id'] ?? null);
            $lineItems = $this->prepareSaleItems($companyId, $branchId, $items);
            $totals = $this->calculateTotals(
                $lineItems->sum('line_subtotal'),
                $lineItems->sum('discount_amount'),
                $lineItems->sum('tax_amount'),
            );

            $sale->items()->delete();

            $attributesToUpdate = [
                'branch_id' => $branchId,
                'currency' => $branch->currency,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer?->id,
                'customer_tax_number' => $customer?->tax_number,
                'payment_terms_days' => $customer && ! $customer->is_walk_in ? ($customer->payment_terms_days ?? 7) : null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => $this->formatDecimal(0),
                'balance_due' => $totals['grand_total'],
                'notes' => $attributes['notes'] ?? null,
                'completed_at' => null,
                'cashier_shift_id' => $attributes['cashier_shift_id'] ?? null,
            ];

            if ($this->supportsHeldSaleCancellationAudit()) {
                $attributesToUpdate += [
                    'cancelled_at' => null,
                    'cancelled_by' => null,
                    'cancellation_reason' => null,
                    'cancellation_notes' => null,
                ];
            }

            $sale->forceFill($attributesToUpdate)->save();

            foreach ($lineItems as $lineItem) {
                SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'company_id' => $companyId,
                    'product_id' => $lineItem['product']->id,
                    'description' => $lineItem['description'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['unit_price'],
                    'unit_cost' => $lineItem['unit_cost'],
                    'discount_amount' => $lineItem['discount_amount'],
                    'tax_rate' => $lineItem['tax_rate'],
                    'tax_amount' => $lineItem['tax_amount'],
                    'tax_category' => $lineItem['tax_category'],
                    'line_total' => $lineItem['line_total'],
                ]);
            }

            return $sale->fresh('items', 'payments');
        });
    }

    public function completeHeldSale(
        string $saleId,
        string $companyId,
        string $branchId,
        string $warehouseId,
        array $items,
        array $payments = [],
        array $attributes = [],
    ): Sale {
        return DB::transaction(function () use ($saleId, $companyId, $branchId, $warehouseId, $items, $payments, $attributes): Sale {
            $sale = $this->updateHeldSale($saleId, $companyId, $branchId, $warehouseId, $items, $attributes);
            $sale->refresh();
            $sale->load('items');

            $customer = $this->resolveCustomer($companyId, $sale->customer_id);

            $this->finalizeSale($sale, $payments, $customer, [
                'created_by' => $attributes['created_by'] ?? null,
                'completed_at' => $attributes['completed_at'] ?? now(),
            ]);
            $this->notifySaleAfterCommit($sale);

            return $sale->fresh('items', 'payments');
        });
    }

    public function cancelHeldSale(
        string $saleId,
        string $companyId,
        string $reason,
        ?string $notes = null,
        ?string $cancelledBy = null,
    ): Sale {
        return DB::transaction(function () use ($saleId, $companyId, $reason, $notes, $cancelledBy): Sale {
            $sale = Sale::query()
                ->lockForUpdate()
                ->where('company_id', $companyId)
                ->findOrFail($saleId);

            if ($sale->status !== SaleStatus::Held) {
                throw new TransactionException('Only held sales can be cancelled.');
            }

            $attributesToUpdate = [
                'status' => SaleStatus::Cancelled,
                'paid_total' => 0,
                'balance_due' => 0,
                'due_date' => null,
            ];

            if ($this->supportsHeldSaleCancellationAudit()) {
                $attributesToUpdate += [
                    'cancelled_at' => now(),
                    'cancelled_by' => $cancelledBy,
                    'cancellation_reason' => $reason,
                    'cancellation_notes' => $notes,
                ];
            } else {
                $attributesToUpdate['notes'] = trim(implode("\n\n", array_filter([
                    $sale->notes,
                    sprintf('Cancelled held sale: %s%s', $reason, $notes ? " - {$notes}" : ''),
                ])));
            }

            $sale->forceFill($attributesToUpdate)->save();

            return $sale->fresh('items', 'payments');
        });
    }

    public function returnSale(string $saleId, array $returnQuantities, array $attributes = []): SaleReturn
    {
        return DB::transaction(function () use ($saleId, $returnQuantities, $attributes): SaleReturn {
            $sale = Sale::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($saleId);

            if (! in_array($sale->status, [SaleStatus::Completed, SaleStatus::PartiallyRefunded, SaleStatus::Refunded], true)) {
                throw new TransactionException('Only completed sales can be returned.');
            }

            $saleItems = $sale->items->keyBy('id');
            $returnedByItem = SaleReturnItem::query()
                ->selectRaw('sale_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
                ->whereIn('sale_item_id', $saleItems->keys())
                ->groupBy('sale_item_id')
                ->pluck('returned_quantity', 'sale_item_id');

            $lineItems = collect();

            foreach ($returnQuantities as $itemId => $quantity) {
                $numericQuantity = $this->normalizePositiveDecimal($quantity, 'Return quantity');
                /** @var SaleItem|null $saleItem */
                $saleItem = $saleItems->get($itemId);

                if (! $saleItem) {
                    throw new TransactionException('Return item does not belong to the sale.');
                }

                $alreadyReturned = (float) ($returnedByItem[$itemId] ?? 0);
                $maxReturnable = round((float) $saleItem->quantity - $alreadyReturned, 4);

                if ($numericQuantity > $maxReturnable + 0.0001) {
                    throw new TransactionException('Cannot return more than the quantity originally sold.');
                }

                $netLine = round(($numericQuantity * (float) $saleItem->unit_price) - ((float) $saleItem->discount_amount * ($numericQuantity / (float) $saleItem->quantity)), 4);
                $taxAmount = round($netLine * ((float) $saleItem->tax_rate / 100), 4);
                $lineTotal = round($netLine + $taxAmount, 4);

                $lineItems->push([
                    'sale_item' => $saleItem,
                    'quantity' => $numericQuantity,
                    'unit_price' => (float) $saleItem->unit_price,
                    'unit_cost' => (float) $saleItem->unit_cost,
                    'tax_rate' => (float) $saleItem->tax_rate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ]);
            }

            if ($lineItems->isEmpty()) {
                throw new TransactionException('At least one sale return item is required.');
            }

            $saleReturn = SaleReturn::query()->create([
                'company_id' => $sale->company_id,
                'sale_id' => $sale->id,
                'warehouse_id' => $sale->warehouse_id,
                'customer_id' => $sale->customer_id,
                'sale_return_number' => $attributes['sale_return_number'] ?? $this->numberSequenceService->next($sale->company_id, 'sale_return'),
                'return_date' => $attributes['return_date'] ?? Carbon::now(config('app.business_timezone'))->toDateString(),
                'subtotal' => $this->formatDecimal($lineItems->sum(fn (array $line): float => $line['quantity'] * $line['unit_price'])),
                'tax_total' => $this->formatDecimal($lineItems->sum('tax_amount')),
                'grand_total' => $this->formatDecimal($lineItems->sum('line_total')),
                'refund_status' => $attributes['refund_status'] ?? 'pending',
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
            ]);

            foreach ($lineItems as $lineItem) {
                SaleReturnItem::query()->create([
                    'sale_return_id' => $saleReturn->id,
                    'sale_item_id' => $lineItem['sale_item']->id,
                    'company_id' => $sale->company_id,
                    'product_id' => $lineItem['sale_item']->product_id,
                    'quantity' => $this->formatDecimal($lineItem['quantity']),
                    'unit_price' => $this->formatDecimal($lineItem['unit_price']),
                    'unit_cost' => $this->formatDecimal($lineItem['unit_cost']),
                    'tax_rate' => $this->formatDecimal($lineItem['tax_rate']),
                    'tax_amount' => $this->formatDecimal($lineItem['tax_amount']),
                    'line_total' => $this->formatDecimal($lineItem['line_total']),
                ]);

                $product = Product::query()->find($lineItem['sale_item']->product_id);

                if ($product?->track_inventory) {
                    $this->inventoryService->increaseWithReference(
                        $sale->company_id,
                        $sale->warehouse_id,
                        $lineItem['sale_item']->product_id,
                        $lineItem['quantity'],
                        StockMovementType::SaleReturn,
                        SaleReturn::class,
                        $saleReturn->id,
                        $saleReturn->sale_return_number,
                        $lineItem['unit_cost'],
                        $attributes['created_by'] ?? null,
                        'Sale return',
                        $attributes['notes'] ?? null,
                        isset($attributes['return_date']) ? Carbon::parse($attributes['return_date']) : now(),
                    );
                }
            }

            if ($sale->customer_id) {
                $saleReceivable = (float) $this->saleReceivableBalance($sale->id);
                $creditReduction = min((float) $saleReturn->grand_total, $saleReceivable);

                if ($creditReduction > 0) {
                    $this->customerLedgerService->recordTransaction($sale->company_id, $sale->customer_id, CustomerTransactionType::SaleReturn, -$creditReduction, [
                        'reference_type' => Sale::class,
                        'reference_id' => $sale->id,
                        'reference_number' => $sale->sale_number,
                        'description' => $attributes['notes'] ?? 'Sale return',
                        'created_by' => $attributes['created_by'] ?? null,
                        'occurred_at' => isset($attributes['return_date']) ? Carbon::parse($attributes['return_date']) : now(),
                    ]);

                    $sale->forceFill([
                        'balance_due' => $this->formatDecimal(max(0, (float) $sale->balance_due - $creditReduction)),
                    ])->save();
                }
            }

            $fullyReturned = $sale->items->every(function (SaleItem $item): bool {
                $returned = (float) SaleReturnItem::query()->where('sale_item_id', $item->id)->sum('quantity');

                return $returned >= (float) $item->quantity;
            });

            $sale->forceFill([
                'status' => $fullyReturned ? SaleStatus::Refunded : SaleStatus::PartiallyRefunded,
            ])->save();

            return $saleReturn->load('items');
        });
    }

    public function grossProfitForSale(string $saleId): string
    {
        $sale = Sale::query()
            ->with(['items', 'returns.items'])
            ->findOrFail($saleId);

        $saleRevenue = $sale->items->sum(fn (SaleItem $item): float => (float) $item->line_total);
        $saleCost = $sale->items->sum(fn (SaleItem $item): float => round((float) $item->quantity * (float) $item->unit_cost, 4));
        $returnRevenue = $sale->returns->flatMap->items->sum(fn (SaleReturnItem $item): float => (float) $item->line_total);
        $returnCost = $sale->returns->flatMap->items->sum(fn (SaleReturnItem $item): float => round((float) $item->quantity * (float) $item->unit_cost, 4));

        return $this->formatDecimal(($saleRevenue - $returnRevenue) - ($saleCost - $returnCost));
    }

    private function finalizeSale(Sale $sale, array $payments, ?Customer $customer, array $attributes): void
    {
        $sale->loadMissing('items');

        $paymentTotal = 0.0;

        foreach ($payments as $payment) {
            $amount = $this->normalizePositiveDecimal($payment['amount'] ?? null, 'Sale payment amount');
            $amountTendered = isset($payment['amount_tendered']) ? $this->normalizeNonNegativeDecimal($payment['amount_tendered'], 'Amount tendered') : null;
            $changeDue = 0.0;

            if ($amountTendered !== null) {
                if ($amountTendered + 0.0001 < $amount) {
                    throw new TransactionException('Amount tendered cannot be less than the applied payment amount.');
                }

                $changeDue = round($amountTendered - $amount, 4);
            }

            $paymentTotal += $amount;

            SalePayment::query()->create([
                'company_id' => $sale->company_id,
                'sale_id' => $sale->id,
                'payment_method' => $payment['payment_method'] ?? 'cash',
                'currency' => $sale->currency,
                'amount' => $this->formatDecimal($amount),
                'amount_tendered' => $amountTendered !== null ? $this->formatDecimal($amountTendered) : null,
                'change_due' => $this->formatDecimal($changeDue),
                'reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null,
                'paid_at' => $payment['paid_at'] ?? now(),
                'created_by' => $attributes['created_by'] ?? null,
            ]);
        }

        if ($paymentTotal > (float) $sale->grand_total + 0.0001) {
            throw new TransactionException('Sale payments cannot exceed the sale total.');
        }

        $balanceDue = round((float) $sale->grand_total - $paymentTotal, 4);

        if ($balanceDue > 0) {
            if (! $customer) {
                throw new TransactionException('Credit sales require a customer.');
            }

            if ($customer->is_walk_in) {
                throw new TransactionException('Walk-in customer cannot receive credit sales.');
            }

            if ($customer->credit_limit !== null) {
                $currentBalance = (float) $this->customerLedgerService->currentBalance($customer->id, $sale->currency);

                if ($currentBalance + $balanceDue > (float) $customer->credit_limit + 0.0001) {
                    throw new TransactionException('Customer credit limit would be exceeded.');
                }
            }
        }

        $posTestMode = in_array($sale->sales_channel, [null, 'pos'], true)
            && (bool) $sale->company()->value('pos_test_mode');
        foreach ($sale->items as $item) {
            $product = Product::query()->find($item->product_id);

            if ($product?->track_inventory) {
                $this->inventoryService->decreaseWithReference(
                    $sale->company_id,
                    $sale->warehouse_id,
                    $item->product_id,
                    $item->quantity,
                    StockMovementType::Sale,
                    Sale::class,
                    $sale->id,
                    $sale->sale_number,
                    (float) $item->unit_cost,
                    $attributes['created_by'] ?? null,
                    'Sale completed',
                    $sale->notes,
                    isset($attributes['completed_at']) && $attributes['completed_at'] instanceof Carbon ? $attributes['completed_at'] : now(),
                    allowNegativeStockOverride: $posTestMode,
                );
            }
        }

        if ($balanceDue > 0) {
            $this->customerLedgerService->recordTransaction($sale->company_id, $customer->id, CustomerTransactionType::Sale, $balanceDue, [
                'reference_type' => Sale::class,
                'reference_id' => $sale->id,
                'reference_number' => $sale->sale_number,
                'description' => 'Credit sale',
                'created_by' => $attributes['created_by'] ?? null,
                'occurred_at' => $attributes['completed_at'] ?? now(),
                'currency' => $sale->currency,
            ]);
        }

        $paymentTermsDays = $customer && ! $customer->is_walk_in ? ($customer->payment_terms_days ?? 7) : null;
        $dueDate = $balanceDue > 0 && $paymentTermsDays !== null
            ? Carbon::parse($sale->sale_date)->addDays($paymentTermsDays)->toDateString()
            : null;

        $sale->forceFill([
            'status' => SaleStatus::Completed,
            'customer_tax_number' => $customer?->tax_number,
            'payment_terms_days' => $paymentTermsDays,
            'due_date' => $dueDate,
            'paid_total' => $this->formatDecimal($paymentTotal),
            'balance_due' => $this->formatDecimal($balanceDue),
            'completed_at' => $attributes['completed_at'] ?? now(),
            'receipt_snapshot' => $this->receiptProfileResolver->resolve(
                $sale->company()->firstOrFail(),
                Branch::query()->where('company_id', $sale->company_id)->findOrFail($sale->branch_id),
            ),
        ])->save();
    }

    private function saleReceivableBalance(string $saleId): string
    {
        $balance = CustomerTransaction::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $saleId)
            ->sum('amount');

        return $this->formatDecimal($balance);
    }

    private function notifySaleAfterCommit(Sale $sale): void
    {
        $saleId = $sale->id;
        DB::afterCommit(fn () => $this->telegramSalesBot->notifySale($saleId));
    }

    private function prepareSaleItems(string $companyId, string $branchId, array $items): Collection
    {
        return collect($items)->map(function (array $item) use ($companyId, $branchId): array {
            $product = Product::query()
                ->with('company:id,default_tax_rate')
                ->where('company_id', $companyId)
                ->find($item['product_id'] ?? null);

            if (! $product) {
                throw new TransactionException('Sale item product does not belong to the selected company.');
            }

            $quantity = $this->normalizePositiveDecimal($item['quantity'] ?? null, 'Sale quantity');
            $branchPrice = ProductBranchPrice::query()->where('branch_id', $branchId)->where('product_id', $product->id)->first();
            $unitPrice = $this->normalizeNonNegativeDecimal($item['unit_price'] ?? $branchPrice?->selling_price ?? $product->selling_price, 'Unit price');
            $unitCost = $this->normalizeNonNegativeDecimal($item['unit_cost'] ?? $branchPrice?->cost_price ?? $product->cost_price, 'Unit cost');
            $discountAmount = $this->normalizeNonNegativeDecimal($item['discount_amount'] ?? 0, 'Discount amount');
            $taxRate = $this->normalizeNonNegativeDecimal($product->effectiveTaxRate(), 'Tax rate');
            $lineSubtotal = round($quantity * $unitPrice, 4);
            $taxBase = round($lineSubtotal - $discountAmount, 4);
            $taxAmount = round($taxBase * ($taxRate / 100), 4);
            $lineTotal = round($taxBase + $taxAmount, 4);

            return [
                'product' => $product,
                'description' => $item['description'] ?? $product->name,
                'quantity' => $this->formatDecimal($quantity),
                'unit_price' => $this->formatDecimal($unitPrice),
                'unit_cost' => $this->formatDecimal($unitCost),
                'discount_amount' => $this->formatDecimal($discountAmount),
                'tax_rate' => $this->formatDecimal($taxRate),
                'tax_amount' => $this->formatDecimal($taxAmount),
                'tax_category' => ($product->tax_category ?? TaxCategory::StandardRated)->value,
                'line_subtotal' => $lineSubtotal,
                'line_total' => $this->formatDecimal($lineTotal),
            ];
        });
    }

    private function calculateTotals(float|int|string $subtotal, float|int|string $discount, float|int|string $tax, float|int|string $deliveryCharge = 0): array
    {
        $grandTotal = round((float) $subtotal - (float) $discount + (float) $tax + (float) $deliveryCharge, 4);

        return [
            'subtotal' => $this->formatDecimal($subtotal),
            'discount_total' => $this->formatDecimal($discount),
            'tax_total' => $this->formatDecimal($tax),
            'grand_total' => $this->formatDecimal($grandTotal),
        ];
    }

    private function resolveWarehouse(string $companyId, string $branchId, string $warehouseId): Warehouse
    {
        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->find($branchId);

        if (! $branch) {
            throw new TransactionException('Branch does not belong to the selected company.');
        }

        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->find($warehouseId);

        if (! $warehouse) {
            throw new TransactionException('Warehouse does not belong to the selected company.');
        }

        if ($warehouse->branch_id !== $branch->id) {
            throw new TransactionException('Warehouse does not belong to the selected branch.');
        }

        return $warehouse;
    }

    private function resolveCustomer(string $companyId, ?string $customerId): ?Customer
    {
        if (! $customerId) {
            return null;
        }

        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->find($customerId);

        if (! $customer) {
            throw new TransactionException('Customer does not belong to the selected company.');
        }

        return $customer;
    }

    private function normalizePositiveDecimal(float|string|null $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new TransactionException("{$label} must be greater than zero.");
        }

        return round((float) $value, 4);
    }

    private function normalizeNonNegativeDecimal(float|string|null $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new TransactionException("{$label} must be zero or greater.");
        }

        return round((float) $value, 4);
    }

    private function formatDecimal(float|string|int $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function supportsHeldSaleCancellationAudit(): bool
    {
        static $supportsAuditColumns;

        if ($supportsAuditColumns === null) {
            $supportsAuditColumns = Schema::hasColumns('sales', [
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
                'cancellation_notes',
            ]);
        }

        return $supportsAuditColumns;
    }
}
