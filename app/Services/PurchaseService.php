<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\StockMovementType;
use App\Enums\SupplierTransactionType;
use App\Enums\TaxCategory;
use App\Exceptions\TransactionException;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductBranchPrice;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly NumberSequenceService $numberSequenceService,
        private readonly SupplierLedgerService $supplierLedgerService,
    ) {}

    public function createPurchase(
        string $companyId,
        string $warehouseId,
        string $supplierId,
        array $items,
        array $attributes = [],
    ): Purchase {
        return DB::transaction(function () use ($companyId, $warehouseId, $supplierId, $items, $attributes): Purchase {
            $warehouse = $this->resolveWarehouse($companyId, $warehouseId);
            $supplier = $this->resolveSupplier($companyId, $supplierId);
            $branch = $this->resolveBranch($companyId, $attributes['branch_id'] ?? null, $warehouse);
            $status = $attributes['status'] ?? PurchaseStatus::Ordered;

            if (! $status instanceof PurchaseStatus) {
                $status = PurchaseStatus::from($status);
            }

            if ($items === []) {
                throw new TransactionException('At least one purchase item is required.');
            }

            $lineItems = $this->preparePurchaseItems($companyId, $branch?->id, $items);
            $totals = $this->calculateTotals(
                $lineItems->sum('line_subtotal'),
                $lineItems->sum('discount_amount'),
                $lineItems->sum('tax_amount'),
                $lineItems->sum('tax_to_add'),
                $attributes['shipping_total'] ?? 0,
                $attributes['other_cost_total'] ?? 0,
            );

            $purchase = Purchase::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch?->id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'purchase_number' => $attributes['purchase_number'] ?? $this->numberSequenceService->next($companyId, 'purchase'),
                'supplier_invoice_number' => $attributes['supplier_invoice_number'] ?? null,
                'status' => $status,
                'currency' => $branch?->currency ?? 'MVR',
                'currency' => $branch?->currency ?? 'MVR',
                'purchase_date' => $attributes['purchase_date'] ?? now()->toDateString(),
                'expected_date' => $attributes['expected_date'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'shipping_total' => $totals['shipping_total'],
                'other_cost_total' => $totals['other_cost_total'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => $this->formatDecimal(0),
                'balance_due' => $totals['grand_total'],
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
            ]);

            foreach ($lineItems as $lineItem) {
                PurchaseItem::query()->create([
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'product_id' => $lineItem['product']->id,
                    'description' => $lineItem['description'],
                    'ordered_quantity' => $lineItem['ordered_quantity'],
                    'received_quantity' => $this->formatDecimal(0),
                    'unit_cost' => $lineItem['unit_cost'],
                    'discount_amount' => $lineItem['discount_amount'],
                    'tax_rate' => $lineItem['tax_rate'],
                    'tax_amount' => $lineItem['tax_amount'],
                    'tax_category' => $lineItem['tax_category'],
                    'price_includes_tax' => $lineItem['price_includes_tax'],
                    'input_tax_claimable' => $lineItem['input_tax_claimable'],
                    'line_total' => $lineItem['line_total'],
                ]);
            }

            if ($status !== PurchaseStatus::Draft && $status !== PurchaseStatus::Cancelled) {
                $this->supplierLedgerService->recordTransaction($companyId, $supplier->id, SupplierTransactionType::Purchase, $purchase->grand_total, [
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'reference_number' => $purchase->purchase_number,
                    'description' => 'Purchase created',
                    'created_by' => $attributes['created_by'] ?? null,
                    'occurred_at' => Carbon::parse($purchase->purchase_date),
                ]);
            }

            return $purchase->load('items', 'supplier', 'warehouse');
        });
    }

    public function updatePurchase(
        string $purchaseId,
        string $companyId,
        string $warehouseId,
        string $supplierId,
        array $items,
        array $attributes = [],
    ): Purchase {
        return DB::transaction(function () use ($purchaseId, $companyId, $warehouseId, $supplierId, $items, $attributes): Purchase {
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->with(['items', 'payments'])
                ->where('company_id', $companyId)
                ->findOrFail($purchaseId);

            if (! in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true)) {
                throw new TransactionException('Only draft or ordered purchases can be edited.');
            }

            if ($purchase->payments->isNotEmpty()) {
                throw new TransactionException('Purchases with payments cannot be edited.');
            }

            if ($purchase->items->contains(fn (PurchaseItem $item): bool => (float) $item->received_quantity > 0.0001)) {
                throw new TransactionException('Received purchases cannot be edited.');
            }

            $warehouse = $this->resolveWarehouse($companyId, $warehouseId);
            $supplier = $this->resolveSupplier($companyId, $supplierId);
            $branch = $this->resolveBranch($companyId, $attributes['branch_id'] ?? null, $warehouse);
            $status = $attributes['status'] ?? $purchase->status;

            if (! $status instanceof PurchaseStatus) {
                $status = PurchaseStatus::from($status);
            }

            if ($items === []) {
                throw new TransactionException('At least one purchase item is required.');
            }

            $lineItems = $this->preparePurchaseItems($companyId, $branch?->id, $items);
            $totals = $this->calculateTotals(
                $lineItems->sum('line_subtotal'),
                $lineItems->sum('discount_amount'),
                $lineItems->sum('tax_amount'),
                $lineItems->sum('tax_to_add'),
                $attributes['shipping_total'] ?? 0,
                $attributes['other_cost_total'] ?? 0,
            );

            $this->resetPurchaseLedger($purchase);
            $purchase->items()->delete();

            $purchase->forceFill([
                'branch_id' => $branch?->id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => $attributes['supplier_invoice_number'] ?? null,
                'status' => $status,
                'purchase_date' => $attributes['purchase_date'] ?? now()->toDateString(),
                'expected_date' => $attributes['expected_date'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'shipping_total' => $totals['shipping_total'],
                'other_cost_total' => $totals['other_cost_total'],
                'grand_total' => $totals['grand_total'],
                'paid_total' => $this->formatDecimal(0),
                'balance_due' => $totals['grand_total'],
                'notes' => $attributes['notes'] ?? null,
            ])->save();

            foreach ($lineItems as $lineItem) {
                PurchaseItem::query()->create([
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'product_id' => $lineItem['product']->id,
                    'description' => $lineItem['description'],
                    'ordered_quantity' => $lineItem['ordered_quantity'],
                    'received_quantity' => $this->formatDecimal(0),
                    'unit_cost' => $lineItem['unit_cost'],
                    'discount_amount' => $lineItem['discount_amount'],
                    'tax_rate' => $lineItem['tax_rate'],
                    'tax_amount' => $lineItem['tax_amount'],
                    'tax_category' => $lineItem['tax_category'],
                    'price_includes_tax' => $lineItem['price_includes_tax'],
                    'input_tax_claimable' => $lineItem['input_tax_claimable'],
                    'line_total' => $lineItem['line_total'],
                ]);
            }

            $this->recordPurchaseLedgerIfRecognized($purchase, $status, $attributes['created_by'] ?? null);

            return $purchase->fresh(['items.product', 'supplier', 'warehouse', 'payments']);
        });
    }

    public function receivePurchase(
        string $purchaseId,
        array $receivedQuantities,
        ?string $receivedBy = null,
        ?Carbon $receivedAt = null,
    ): Purchase {
        return DB::transaction(function () use ($purchaseId, $receivedQuantities, $receivedBy, $receivedAt): Purchase {
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($purchaseId);

            if ($purchase->status === PurchaseStatus::Cancelled) {
                throw new TransactionException('Cancelled purchases cannot be received.');
            }

            if ($purchase->status === PurchaseStatus::Draft) {
                throw new TransactionException('Draft purchases must be confirmed before receiving inventory.');
            }

            $items = $purchase->items->keyBy('id');
            $didReceive = false;
            $occurredAt = $receivedAt ?? now();

            foreach ($receivedQuantities as $itemId => $quantity) {
                $numericQuantity = $this->normalizePositiveDecimal($quantity, 'Received quantity');
                /** @var PurchaseItem|null $item */
                $item = $items->get($itemId);

                if (! $item) {
                    throw new TransactionException('Received item does not belong to the purchase.');
                }

                $remaining = round((float) $item->ordered_quantity - (float) $item->received_quantity, 4);

                if ($numericQuantity > $remaining + 0.0001) {
                    throw new TransactionException('Cannot receive more than the remaining ordered quantity.');
                }

                $item->forceFill([
                    'received_quantity' => $this->formatDecimal((float) $item->received_quantity + $numericQuantity),
                ])->save();
                $inventoryUnitCost = $this->inventoryUnitCost($item);

                $this->inventoryService->increaseWithReference(
                    $purchase->company_id,
                    $purchase->warehouse_id,
                    $item->product_id,
                    $numericQuantity,
                    StockMovementType::Purchase,
                    Purchase::class,
                    $purchase->id,
                    $purchase->purchase_number,
                    $inventoryUnitCost,
                    $receivedBy,
                    'Purchase receipt',
                    $purchase->notes,
                    $occurredAt,
                );

                $this->syncProductAverageCosts($purchase, $item->product_id);

                $didReceive = true;
            }

            if (! $didReceive) {
                throw new TransactionException('At least one positive received quantity is required.');
            }

            $purchase->refresh();
            $allReceived = $purchase->items()->get()->every(fn (PurchaseItem $item): bool => (float) $item->received_quantity >= (float) $item->ordered_quantity);

            $purchase->forceFill([
                'status' => $allReceived ? PurchaseStatus::Received : PurchaseStatus::PartiallyReceived,
                'received_by' => $receivedBy,
                'received_at' => $occurredAt,
            ])->save();

            return $purchase->fresh('items');
        });
    }

    public function recordPayment(
        string $purchaseId,
        float|string $amount,
        string $paymentMethod,
        array $attributes = [],
    ): PurchasePayment {
        $numericAmount = $this->normalizePositiveDecimal($amount, 'Purchase payment amount');

        return DB::transaction(function () use ($purchaseId, $numericAmount, $paymentMethod, $attributes): PurchasePayment {
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->findOrFail($purchaseId);

            if ($numericAmount > (float) $purchase->balance_due + 0.0001) {
                throw new TransactionException('Purchase payment cannot exceed the outstanding balance.');
            }

            $payment = PurchasePayment::query()->create([
                'company_id' => $purchase->company_id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'payment_method' => $paymentMethod,
                'currency' => $purchase->currency,
                'amount' => $this->formatDecimal($numericAmount),
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'paid_at' => $attributes['paid_at'] ?? now(),
                'created_by' => $attributes['created_by'] ?? null,
            ]);

            $purchase->forceFill([
                'paid_total' => $this->formatDecimal((float) $purchase->paid_total + $numericAmount),
                'balance_due' => $this->formatDecimal((float) $purchase->balance_due - $numericAmount),
            ])->save();

            $this->supplierLedgerService->recordTransaction($purchase->company_id, $purchase->supplier_id, SupplierTransactionType::Payment, -$numericAmount, [
                'reference_type' => Purchase::class,
                'reference_id' => $purchase->id,
                'reference_number' => $purchase->purchase_number,
                'description' => $attributes['notes'] ?? 'Purchase payment',
                'created_by' => $attributes['created_by'] ?? null,
                'occurred_at' => $attributes['paid_at'] ?? now(),
            ]);

            return $payment;
        });
    }

    public function cancelPurchase(string $purchaseId, ?string $cancelledBy = null, ?string $notes = null): Purchase
    {
        return DB::transaction(function () use ($purchaseId, $notes): Purchase {
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->with(['items', 'payments'])
                ->findOrFail($purchaseId);

            if ($purchase->status === PurchaseStatus::Cancelled) {
                return $purchase->fresh(['items', 'payments']);
            }

            if (! in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true)) {
                throw new TransactionException('Only draft or ordered purchases can be cancelled.');
            }

            if ($purchase->payments->isNotEmpty()) {
                throw new TransactionException('Purchases with recorded payments cannot be cancelled.');
            }

            if ($purchase->items->contains(fn (PurchaseItem $item): bool => (float) $item->received_quantity > 0.0001)) {
                throw new TransactionException('Received purchases cannot be cancelled. Use purchase returns for received items.');
            }

            $this->resetPurchaseLedger($purchase);

            $purchase->forceFill([
                'status' => PurchaseStatus::Cancelled,
                'notes' => trim(implode("\n\n", array_filter([$purchase->notes, $notes]))),
            ])->save();

            return $purchase->fresh(['items', 'payments']);
        });
    }

    public function returnPurchase(
        string $purchaseId,
        array $returnQuantities,
        array $attributes = [],
    ): PurchaseReturn {
        return DB::transaction(function () use ($purchaseId, $returnQuantities, $attributes): PurchaseReturn {
            $purchase = Purchase::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($purchaseId);

            if ($purchase->status === PurchaseStatus::Cancelled) {
                throw new TransactionException('Cancelled purchases cannot be returned.');
            }

            $purchaseItems = $purchase->items->keyBy('id');
            $returnedByItem = PurchaseReturnItem::query()
                ->selectRaw('purchase_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
                ->whereIn('purchase_item_id', $purchaseItems->keys())
                ->groupBy('purchase_item_id')
                ->pluck('returned_quantity', 'purchase_item_id');

            $lineItems = collect();

            foreach ($returnQuantities as $itemId => $quantity) {
                $numericQuantity = $this->normalizePositiveDecimal($quantity, 'Return quantity');
                /** @var PurchaseItem|null $purchaseItem */
                $purchaseItem = $purchaseItems->get($itemId);

                if (! $purchaseItem) {
                    throw new TransactionException('Return item does not belong to the purchase.');
                }

                $alreadyReturned = (float) ($returnedByItem[$itemId] ?? 0);
                $maxReturnable = round((float) $purchaseItem->received_quantity - $alreadyReturned, 4);

                if ($numericQuantity > $maxReturnable + 0.0001) {
                    throw new TransactionException('Cannot return more than the quantity previously received.');
                }

                $taxBase = round($numericQuantity * (float) $purchaseItem->unit_cost, 4);
                $taxRate = (float) $purchaseItem->tax_rate;
                $taxAmount = round($purchaseItem->price_includes_tax && $taxRate > 0
                    ? $taxBase * ($taxRate / (100 + $taxRate))
                    : $taxBase * ($taxRate / 100), 4);
                $lineTotal = round($purchaseItem->price_includes_tax ? $taxBase : $taxBase + $taxAmount, 4);

                $lineItems->push([
                    'purchase_item' => $purchaseItem,
                    'quantity' => $numericQuantity,
                    'unit_cost' => (float) $purchaseItem->unit_cost,
                    'tax_rate' => (float) $purchaseItem->tax_rate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ]);
            }

            if ($lineItems->isEmpty()) {
                throw new TransactionException('At least one return item is required.');
            }

            $purchaseReturn = PurchaseReturn::query()->create([
                'company_id' => $purchase->company_id,
                'purchase_id' => $purchase->id,
                'warehouse_id' => $purchase->warehouse_id,
                'supplier_id' => $purchase->supplier_id,
                'purchase_return_number' => $attributes['purchase_return_number'] ?? $this->numberSequenceService->next($purchase->company_id, 'purchase_return'),
                'return_date' => $attributes['return_date'] ?? now(config('app.business_timezone'))->toDateString(),
                'subtotal' => $this->formatDecimal($lineItems->sum(fn (array $line): float => $line['quantity'] * $line['unit_cost'])),
                'tax_total' => $this->formatDecimal($lineItems->sum('tax_amount')),
                'grand_total' => $this->formatDecimal($lineItems->sum('line_total')),
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
            ]);

            foreach ($lineItems as $lineItem) {
                PurchaseReturnItem::query()->create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'purchase_item_id' => $lineItem['purchase_item']->id,
                    'company_id' => $purchase->company_id,
                    'product_id' => $lineItem['purchase_item']->product_id,
                    'quantity' => $this->formatDecimal($lineItem['quantity']),
                    'unit_cost' => $this->formatDecimal($lineItem['unit_cost']),
                    'tax_rate' => $this->formatDecimal($lineItem['tax_rate']),
                    'tax_amount' => $this->formatDecimal($lineItem['tax_amount']),
                    'line_total' => $this->formatDecimal($lineItem['line_total']),
                ]);

                $this->inventoryService->decreaseWithReference(
                    $purchase->company_id,
                    $purchase->warehouse_id,
                    $lineItem['purchase_item']->product_id,
                    $lineItem['quantity'],
                    StockMovementType::PurchaseReturn,
                    PurchaseReturn::class,
                    $purchaseReturn->id,
                    $purchaseReturn->purchase_return_number,
                    $this->inventoryUnitCost($lineItem['purchase_item']),
                    $attributes['created_by'] ?? null,
                    'Purchase return',
                    $attributes['notes'] ?? null,
                    isset($attributes['return_date']) ? Carbon::parse($attributes['return_date']) : now(),
                );
            }

            $this->supplierLedgerService->recordTransaction($purchase->company_id, $purchase->supplier_id, SupplierTransactionType::PurchaseReturn, -((float) $purchaseReturn->grand_total), [
                'reference_type' => PurchaseReturn::class,
                'reference_id' => $purchaseReturn->id,
                'reference_number' => $purchaseReturn->purchase_return_number,
                'description' => $attributes['notes'] ?? 'Purchase return',
                'created_by' => $attributes['created_by'] ?? null,
                'occurred_at' => isset($attributes['return_date']) ? Carbon::parse($attributes['return_date']) : now(),
            ]);

            $returnTotal = (float) PurchaseReturn::query()
                ->where('purchase_id', $purchase->id)
                ->sum('grand_total');

            $purchase->forceFill([
                'balance_due' => $this->formatDecimal(max(0, (float) $purchase->grand_total - $returnTotal - (float) $purchase->paid_total)),
            ])->save();

            return $purchaseReturn->load('items');
        });
    }

    private function preparePurchaseItems(string $companyId, ?string $branchId, array $items): Collection
    {
        return collect($items)->map(function (array $item) use ($companyId, $branchId): array {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->find($item['product_id'] ?? null);

            if (! $product) {
                throw new TransactionException('Purchase item product does not belong to the selected company.');
            }

            $orderedQuantity = $this->normalizePositiveDecimal($item['ordered_quantity'] ?? $item['quantity'] ?? null, 'Ordered quantity');
            $branchCost = $branchId ? ProductBranchPrice::query()->where('branch_id', $branchId)->where('product_id', $product->id)->value('cost_price') : null;
            $unitCost = $this->normalizeNonNegativeDecimal($item['unit_cost'] ?? $branchCost ?? $product->cost_price, 'Unit cost');
            $discountAmount = $this->normalizeNonNegativeDecimal($item['discount_amount'] ?? 0, 'Discount amount');
            $taxCategory = TaxCategory::tryFrom((string) ($item['tax_category'] ?? ''))
                ?? $product->tax_category
                ?? TaxCategory::StandardRated;
            $taxRate = $taxCategory === TaxCategory::StandardRated
                ? $this->normalizeNonNegativeDecimal($item['tax_rate'] ?? $product->effectiveTaxRate(), 'Tax rate')
                : 0.0;
            $priceIncludesTax = (bool) ($item['price_includes_tax'] ?? false);
            $inputTaxClaimable = $taxCategory === TaxCategory::StandardRated
                && $taxRate > 0
                && (bool) ($item['input_tax_claimable'] ?? true);
            $lineSubtotal = round($orderedQuantity * $unitCost, 4);
            $taxBase = round($lineSubtotal - $discountAmount, 4);
            $taxAmount = round($priceIncludesTax && $taxRate > 0
                ? $taxBase * ($taxRate / (100 + $taxRate))
                : $taxBase * ($taxRate / 100), 4);
            $taxToAdd = $priceIncludesTax ? 0.0 : $taxAmount;
            $lineTotal = round($taxBase + $taxToAdd, 4);

            return [
                'product' => $product,
                'description' => $item['description'] ?? $product->name,
                'ordered_quantity' => $this->formatDecimal($orderedQuantity),
                'unit_cost' => $this->formatDecimal($unitCost),
                'discount_amount' => $this->formatDecimal($discountAmount),
                'tax_rate' => $this->formatDecimal($taxRate),
                'tax_amount' => $this->formatDecimal($taxAmount),
                'tax_category' => $taxCategory->value,
                'price_includes_tax' => $priceIncludesTax,
                'input_tax_claimable' => $inputTaxClaimable,
                'tax_to_add' => $taxToAdd,
                'line_subtotal' => $lineSubtotal,
                'line_total' => $this->formatDecimal($lineTotal),
            ];
        });
    }

    private function inventoryUnitCost(PurchaseItem $item): float
    {
        $unitCost = (float) $item->unit_cost;
        $taxRate = (float) $item->tax_rate;

        if ($taxRate <= 0) {
            return $unitCost;
        }

        if ($item->price_includes_tax && $item->input_tax_claimable) {
            return round($unitCost / (1 + ($taxRate / 100)), 4);
        }

        if (! $item->price_includes_tax && ! $item->input_tax_claimable) {
            return round($unitCost * (1 + ($taxRate / 100)), 4);
        }

        return $unitCost;
    }

    private function syncProductAverageCosts(Purchase $purchase, string $productId): void
    {
        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        $companyBalances = InventoryBalance::query()
            ->where('company_id', $purchase->company_id)
            ->where('product_id', $productId)
            ->where('quantity', '>', 0)
            ->get();
        $companyAverage = $this->weightedAverageCost($companyBalances, (float) $product->cost_price);

        $product->forceFill(['cost_price' => $this->formatDecimal($companyAverage)])->save();

        if (! $purchase->branch_id) {
            return;
        }

        $branchPrice = ProductBranchPrice::query()
            ->where('company_id', $purchase->company_id)
            ->where('branch_id', $purchase->branch_id)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
        $branchWarehouseIds = Warehouse::query()
            ->where('company_id', $purchase->company_id)
            ->where('branch_id', $purchase->branch_id)
            ->pluck('id');
        $branchBalances = InventoryBalance::query()
            ->where('company_id', $purchase->company_id)
            ->where('product_id', $productId)
            ->whereIn('warehouse_id', $branchWarehouseIds)
            ->where('quantity', '>', 0)
            ->get();
        $branchAverage = $this->weightedAverageCost(
            $branchBalances,
            (float) ($branchPrice?->cost_price ?? $companyAverage),
        );

        ProductBranchPrice::query()->updateOrCreate(
            ['company_id' => $purchase->company_id, 'branch_id' => $purchase->branch_id, 'product_id' => $productId],
            [
                'currency' => $purchase->currency,
                'cost_price' => $this->formatDecimal($branchAverage),
                'selling_price' => $branchPrice?->selling_price ?? $product->selling_price,
            ],
        );
    }

    private function weightedAverageCost(Collection $balances, float $fallbackCost): float
    {
        $quantity = (float) $balances->sum(fn (InventoryBalance $balance): float => (float) $balance->quantity);

        if ($quantity <= 0) {
            return round($fallbackCost, 4);
        }

        $inventoryValue = (float) $balances->sum(fn (InventoryBalance $balance): float => (float) $balance->quantity * (float) ($balance->average_cost ?? $fallbackCost));

        return round($inventoryValue / $quantity, 4);
    }

    private function calculateTotals(float|int|string $subtotal, float|int|string $discount, float|int|string $tax, float|int|string $taxToAdd, float|int|string $shipping, float|int|string $other): array
    {
        $grandTotal = round((float) $subtotal - (float) $discount + (float) $taxToAdd + (float) $shipping + (float) $other, 4);

        return [
            'subtotal' => $this->formatDecimal($subtotal),
            'discount_total' => $this->formatDecimal($discount),
            'tax_total' => $this->formatDecimal($tax),
            'shipping_total' => $this->formatDecimal($shipping),
            'other_cost_total' => $this->formatDecimal($other),
            'grand_total' => $this->formatDecimal($grandTotal),
        ];
    }

    private function resolveWarehouse(string $companyId, string $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->find($warehouseId);

        if (! $warehouse) {
            throw new TransactionException('Warehouse does not belong to the selected company.');
        }

        return $warehouse;
    }

    private function resolveSupplier(string $companyId, string $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->where('company_id', $companyId)
            ->find($supplierId);

        if (! $supplier) {
            throw new TransactionException('Supplier does not belong to the selected company.');
        }

        return $supplier;
    }

    private function resolveBranch(string $companyId, ?string $branchId, Warehouse $warehouse): ?Branch
    {
        if (! $branchId) {
            return null;
        }

        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->find($branchId);

        if (! $branch) {
            throw new TransactionException('Branch does not belong to the selected company.');
        }

        if ($warehouse->branch_id !== $branch->id) {
            throw new TransactionException('Warehouse does not belong to the selected branch.');
        }

        return $branch;
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

    private function recordPurchaseLedgerIfRecognized(Purchase $purchase, PurchaseStatus $status, ?string $createdBy = null): void
    {
        if (in_array($status, [PurchaseStatus::Draft, PurchaseStatus::Cancelled], true)) {
            return;
        }

        $this->supplierLedgerService->recordTransaction($purchase->company_id, $purchase->supplier_id, SupplierTransactionType::Purchase, $purchase->grand_total, [
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'reference_number' => $purchase->purchase_number,
            'description' => 'Purchase created',
            'created_by' => $createdBy,
            'occurred_at' => Carbon::parse($purchase->purchase_date),
        ]);
    }

    private function resetPurchaseLedger(Purchase $purchase): void
    {
        DB::table('supplier_transactions')
            ->where('reference_type', Purchase::class)
            ->where('reference_id', $purchase->id)
            ->delete();
    }
}
