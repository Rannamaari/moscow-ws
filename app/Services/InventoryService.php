<?php

namespace App\Services;

use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InventoryException;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(
        private readonly InventoryQueryService $inventoryQueryService,
    ) {}

    public function setOpeningStock(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        float|string|null $unitCost = null,
        ?string $createdBy = null,
        ?Carbon $occurredAt = null,
        ?string $notes = null,
    ): StockMovement {
        $normalizedQuantity = $this->normalizePositiveQuantity($quantity, 'Opening stock quantity');

        return DB::transaction(function () use ($companyId, $warehouseId, $productId, $normalizedQuantity, $unitCost, $createdBy, $occurredAt, $notes): StockMovement {
            $product = $this->resolveProductAndWarehouse($companyId, $warehouseId, $productId)['product'];

            $existingOpening = StockMovement::query()
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('type', StockMovementType::Opening)
                ->lockForUpdate()
                ->exists();

            if ($existingOpening) {
                throw new InventoryException('Opening stock has already been set for this product and warehouse. Use an adjustment instead.');
            }

            return $this->applyMovement(
                $companyId,
                $warehouseId,
                $product,
                $normalizedQuantity,
                StockMovementType::Opening,
                $unitCost !== null ? (float) $unitCost : null,
                $createdBy,
                $occurredAt,
                null,
                $notes
            );
        });
    }

    public function increase(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        ?float $unitCost = null,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
        StockMovementType $type = StockMovementType::AdjustmentIn,
    ): StockMovement {
        $normalizedQuantity = $this->normalizePositiveQuantity($quantity, 'Increase quantity');
        $product = $this->resolveProductAndWarehouse($companyId, $warehouseId, $productId)['product'];

        return DB::transaction(fn (): StockMovement => $this->applyMovement(
            $companyId,
            $warehouseId,
            $product,
            $normalizedQuantity,
            $type,
            $unitCost,
            $createdBy,
            $occurredAt,
            $reason,
            $notes
        ));
    }

    public function increaseWithReference(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        StockMovementType $type,
        string $referenceType,
        string $referenceId,
        string $referenceNumber,
        ?float $unitCost = null,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
    ): StockMovement {
        $normalizedQuantity = $this->normalizePositiveQuantity($quantity, 'Increase quantity');
        $product = $this->resolveProductAndWarehouse($companyId, $warehouseId, $productId)['product'];

        return DB::transaction(fn (): StockMovement => $this->applyMovement(
            $companyId,
            $warehouseId,
            $product,
            $normalizedQuantity,
            $type,
            $unitCost,
            $createdBy,
            $occurredAt,
            $reason,
            $notes,
            null,
            $referenceType,
            $referenceId,
            $referenceNumber,
        ));
    }

    public function decrease(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        ?float $unitCost = null,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
        StockMovementType $type = StockMovementType::AdjustmentOut,
    ): StockMovement {
        $normalizedQuantity = $this->normalizePositiveQuantity($quantity, 'Decrease quantity');
        $product = $this->resolveProductAndWarehouse($companyId, $warehouseId, $productId)['product'];

        return DB::transaction(fn (): StockMovement => $this->applyMovement(
            $companyId,
            $warehouseId,
            $product,
            $this->formatDecimal(-$normalizedQuantity),
            $type,
            $unitCost,
            $createdBy,
            $occurredAt,
            $reason,
            $notes
        ));
    }

    public function decreaseWithReference(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        StockMovementType $type,
        string $referenceType,
        string $referenceId,
        string $referenceNumber,
        ?float $unitCost = null,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
        bool $allowNegativeStockOverride = false,
    ): StockMovement {
        $normalizedQuantity = $this->normalizePositiveQuantity($quantity, 'Decrease quantity');
        $product = $this->resolveProductAndWarehouse($companyId, $warehouseId, $productId)['product'];

        return DB::transaction(fn (): StockMovement => $this->applyMovement(
            $companyId,
            $warehouseId,
            $product,
            $this->formatDecimal(-$normalizedQuantity),
            $type,
            $unitCost,
            $createdBy,
            $occurredAt,
            $reason,
            $notes,
            null,
            $referenceType,
            $referenceId,
            $referenceNumber,
            $allowNegativeStockOverride,
        ));
    }

    public function recordDamage(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
    ): StockMovement {
        return $this->decrease(
            $companyId,
            $warehouseId,
            $productId,
            $quantity,
            null,
            $createdBy,
            $reason,
            $notes,
            $occurredAt,
            StockMovementType::Damage
        );
    }

    public function recordLoss(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $quantity,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
    ): StockMovement {
        return $this->decrease(
            $companyId,
            $warehouseId,
            $productId,
            $quantity,
            null,
            $createdBy,
            $reason,
            $notes,
            $occurredAt,
            StockMovementType::Loss
        );
    }

    public function adjust(
        string $companyId,
        string $warehouseId,
        string $productId,
        float|string $targetQuantity,
        ?string $createdBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?Carbon $occurredAt = null,
    ): ?StockMovement {
        if (blank($reason) && blank($notes)) {
            throw new InventoryException('A reason or notes is required for manual adjustments.');
        }

        $target = $this->normalizeNonNegativeQuantity($targetQuantity, 'Target quantity');

        return DB::transaction(function () use ($companyId, $warehouseId, $productId, $target, $createdBy, $reason, $notes, $occurredAt): ?StockMovement {
            $resolved = $this->resolveProductAndWarehouse($companyId, $warehouseId, $productId);
            $product = $resolved['product'];
            $balance = $this->lockBalanceRecord($companyId, $warehouseId, $productId);
            $current = $balance ? (float) $balance->quantity : 0.0;
            $delta = round($target - $current, 4);

            if ($delta === 0.0) {
                return null;
            }

            $type = $delta > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut;

            return $this->applyMovement(
                $companyId,
                $warehouseId,
                $product,
                $this->formatDecimal($delta),
                $type,
                null,
                $createdBy,
                $occurredAt,
                $reason,
                $notes,
                $balance
            );
        });
    }

    public function createStockCount(
        string $companyId,
        string $warehouseId,
        array $productIds,
        ?string $createdBy = null,
        ?string $notes = null,
    ): StockCount {
        return DB::transaction(function () use ($companyId, $warehouseId, $productIds, $createdBy, $notes): StockCount {
            $warehouse = Warehouse::query()
                ->where('company_id', $companyId)
                ->find($warehouseId);

            if (! $warehouse) {
                throw new InventoryException('Warehouse does not belong to the selected company.');
            }

            $productIds = array_values(array_unique($productIds));

            if ($productIds === []) {
                throw new InventoryException('At least one product is required to create a stock count.');
            }

            $products = Product::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $productIds)
                ->where('track_inventory', true)
                ->get()
                ->keyBy('id');

            foreach ($productIds as $productId) {
                if (! $products->has($productId)) {
                    throw new InventoryException('Stock counts can only include inventory-tracked products from the same company.');
                }
            }

            $count = StockCount::query()->create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'status' => StockCountStatus::InProgress,
                'started_at' => now(),
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            $balances = $this->inventoryQueryService->getBalancesForProducts($companyId, $warehouseId, $productIds);

            foreach ($productIds as $productId) {
                $product = $products[$productId];

                StockCountItem::query()->create([
                    'stock_count_id' => $count->id,
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'system_quantity' => $balances[$productId],
                    'counted_quantity' => null,
                    'difference' => null,
                    'unit_cost' => $product->cost_price,
                ]);
            }

            return $count->load('items');
        });
    }

    public function setCountedQuantity(string $stockCountItemId, float|string $countedQuantity): StockCountItem
    {
        $counted = $this->normalizeNonNegativeQuantity($countedQuantity, 'Counted quantity');

        return DB::transaction(function () use ($stockCountItemId, $counted): StockCountItem {
            $item = StockCountItem::query()
                ->whereKey($stockCountItemId)
                ->lockForUpdate()
                ->firstOrFail();

            $count = StockCount::query()
                ->whereKey($item->stock_count_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($count->status === StockCountStatus::Completed) {
                throw new InventoryException('Completed stock counts cannot be edited.');
            }

            if ($count->status === StockCountStatus::Cancelled) {
                throw new InventoryException('Cancelled stock counts cannot be edited.');
            }

            $item->forceFill([
                'counted_quantity' => $counted,
                'difference' => $this->formatDecimal((float) $counted - (float) $item->system_quantity),
            ])->save();

            return $item;
        });
    }

    public function completeStockCount(string $stockCountId, ?string $completedBy = null): StockCount
    {
        return DB::transaction(function () use ($stockCountId, $completedBy): StockCount {
            $count = StockCount::query()
                ->whereKey($stockCountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($count->status === StockCountStatus::Completed) {
                throw new InventoryException('Stock count has already been completed.');
            }

            if ($count->status === StockCountStatus::Cancelled) {
                throw new InventoryException('Cancelled stock counts cannot be completed.');
            }

            $items = StockCountItem::query()
                ->where('stock_count_id', $count->id)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if ($item->counted_quantity === null) {
                    continue;
                }

                $resolved = $this->resolveProductAndWarehouse($count->company_id, $count->warehouse_id, $item->product_id);
                $product = $resolved['product'];
                $balance = $this->lockBalanceRecord($count->company_id, $count->warehouse_id, $item->product_id);
                $currentBalance = $balance ? (float) $balance->quantity : 0.0;
                $adjustment = round((float) $item->counted_quantity - $currentBalance, 4);

                if ($adjustment !== 0.0) {
                    $this->applyMovement(
                        $count->company_id,
                        $count->warehouse_id,
                        $product,
                        $this->formatDecimal($adjustment),
                        StockMovementType::StockCount,
                        $item->unit_cost !== null ? (float) $item->unit_cost : null,
                        $completedBy,
                        now(),
                        'Stock count completion',
                        $count->notes,
                        $balance,
                        StockCount::class,
                        $count->id,
                        $count->id
                    );
                }
            }

            $count->forceFill([
                'status' => StockCountStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $completedBy,
            ])->save();

            return $count->fresh('items');
        });
    }

    public function cancelStockCount(string $stockCountId): StockCount
    {
        return DB::transaction(function () use ($stockCountId): StockCount {
            $count = StockCount::query()
                ->whereKey($stockCountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($count->status === StockCountStatus::Completed) {
                throw new InventoryException('Completed stock counts cannot be cancelled.');
            }

            $count->forceFill([
                'status' => StockCountStatus::Cancelled,
            ])->save();

            return $count;
        });
    }

    public function getBalance(string $companyId, string $warehouseId, string $productId): string
    {
        return $this->inventoryQueryService->getBalance($companyId, $warehouseId, $productId);
    }

    private function applyMovement(
        string $companyId,
        string $warehouseId,
        Product $product,
        string $signedQuantity,
        StockMovementType $type,
        ?float $unitCost,
        ?string $createdBy,
        ?Carbon $occurredAt,
        ?string $reason,
        ?string $notes,
        ?InventoryBalance $lockedBalance = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $referenceNumber = null,
        bool $allowNegativeStockOverride = false,
    ): StockMovement {
        $balance = $lockedBalance ?? $this->lockBalanceRecord($companyId, $warehouseId, $product->id);
        $before = $balance ? (float) $balance->quantity : 0.0;
        $beforeAverageCost = $balance?->average_cost !== null
            ? (float) $balance->average_cost
            : (float) $product->cost_price;
        $delta = (float) $signedQuantity;
        $after = round($before + $delta, 4);

        if ($after < 0 && ! $product->allow_negative_stock && ! $allowNegativeStockOverride) {
            throw new InventoryException('Negative stock is not allowed for this product.');
        }

        $balance = $this->ensureBalanceRecord($companyId, $warehouseId, $product->id, $balance);
        $averageCost = $beforeAverageCost;

        if ($delta > 0 && $unitCost !== null) {
            $costedBeforeQuantity = max(0, $before);
            $averageCost = $costedBeforeQuantity <= 0
                ? $unitCost
                : (($costedBeforeQuantity * $beforeAverageCost) + ($delta * $unitCost)) / ($costedBeforeQuantity + $delta);
        }

        $balance->forceFill([
            'quantity' => $this->formatDecimal($after),
            'average_cost' => $this->formatDecimal($averageCost),
        ])->save();

        return StockMovement::query()->create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $signedQuantity,
            'quantity_before' => $this->formatDecimal($before),
            'quantity_after' => $this->formatDecimal($after),
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'reason' => $reason,
            'notes' => $notes,
            'created_by' => $createdBy,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * @return array{product: Product, warehouse: Warehouse}
     */
    private function resolveProductAndWarehouse(string $companyId, string $warehouseId, string $productId): array
    {
        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->find($warehouseId);

        if (! $warehouse) {
            throw new InventoryException('Warehouse does not belong to the selected company.');
        }

        $product = Product::query()
            ->where('company_id', $companyId)
            ->find($productId);

        if (! $product) {
            throw new InventoryException('Product does not belong to the selected company.');
        }

        if (! $product->track_inventory) {
            throw new InventoryException('This product does not track inventory.');
        }

        return [
            'product' => $product,
            'warehouse' => $warehouse,
        ];
    }

    private function lockBalanceRecord(string $companyId, string $warehouseId, string $productId): ?InventoryBalance
    {
        return InventoryBalance::query()
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    private function ensureBalanceRecord(
        string $companyId,
        string $warehouseId,
        string $productId,
        ?InventoryBalance $balance = null,
    ): InventoryBalance {
        if ($balance) {
            return $balance;
        }

        try {
            InventoryBalance::query()->create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => $this->formatDecimal(0),
                'average_cost' => null,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }

        return InventoryBalance::query()
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizePositiveQuantity(float|string $quantity, string $label): float
    {
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw new InventoryException("{$label} must be greater than zero.");
        }

        return round((float) $quantity, 4);
    }

    private function normalizeNonNegativeQuantity(float|string $quantity, string $label): float
    {
        if (! is_numeric($quantity) || (float) $quantity < 0) {
            throw new InventoryException("{$label} must be zero or greater.");
        }

        return round((float) $quantity, 4);
    }

    private function formatDecimal(float|string|int $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
