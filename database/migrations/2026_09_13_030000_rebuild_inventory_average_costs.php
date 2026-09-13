<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_balances', 'average_cost')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('inventory_balances')
                ->select(['id', 'company_id', 'warehouse_id', 'product_id'])
                ->orderBy('id')
                ->each(function ($balance): void {
                    $fallbackCost = (float) (DB::table('products')->where('id', $balance->product_id)->value('cost_price') ?? 0);
                    $averageCost = $fallbackCost;
                    $runningQuantity = 0.0;

                    $movements = DB::table('stock_movements')
                        ->where('company_id', $balance->company_id)
                        ->where('warehouse_id', $balance->warehouse_id)
                        ->where('product_id', $balance->product_id)
                        ->orderBy('occurred_at')
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get(['quantity', 'unit_cost']);

                    foreach ($movements as $movement) {
                        $quantity = (float) $movement->quantity;

                        if ($quantity > 0 && $movement->unit_cost !== null) {
                            $costedQuantity = max(0, $runningQuantity);
                            $incomingCost = (float) $movement->unit_cost;
                            $averageCost = $costedQuantity <= 0
                                ? $incomingCost
                                : (($costedQuantity * $averageCost) + ($quantity * $incomingCost)) / ($costedQuantity + $quantity);
                        }

                        $runningQuantity += $quantity;
                    }

                    DB::table('inventory_balances')
                        ->where('id', $balance->id)
                        ->update(['average_cost' => $this->decimal($averageCost)]);
                });

            DB::table('inventory_balances')
                ->where('quantity', '>', 0)
                ->select(['company_id', 'product_id'])
                ->distinct()
                ->orderBy('company_id')
                ->orderBy('product_id')
                ->get()
                ->each(function ($productBalance): void {
                    $balances = DB::table('inventory_balances')
                        ->where('company_id', $productBalance->company_id)
                        ->where('product_id', $productBalance->product_id)
                        ->where('quantity', '>', 0)
                        ->get(['quantity', 'average_cost']);

                    DB::table('products')
                        ->where('id', $productBalance->product_id)
                        ->update(['cost_price' => $this->decimal($this->weightedAverage($balances))]);
                });

            DB::table('product_branch_prices')
                ->select(['id', 'company_id', 'branch_id', 'product_id'])
                ->orderBy('id')
                ->each(function ($branchPrice): void {
                    $warehouseIds = DB::table('warehouses')
                        ->where('company_id', $branchPrice->company_id)
                        ->where('branch_id', $branchPrice->branch_id)
                        ->pluck('id');
                    $balances = DB::table('inventory_balances')
                        ->where('company_id', $branchPrice->company_id)
                        ->where('product_id', $branchPrice->product_id)
                        ->whereIn('warehouse_id', $warehouseIds)
                        ->where('quantity', '>', 0)
                        ->get(['quantity', 'average_cost']);

                    if ($balances->isNotEmpty()) {
                        DB::table('product_branch_prices')
                            ->where('id', $branchPrice->id)
                            ->update(['cost_price' => $this->decimal($this->weightedAverage($balances))]);
                    }
                });
        });
    }

    public function down(): void
    {
        // Historical stock movements remain the source of truth for this data correction.
    }

    private function weightedAverage(Collection $balances): float
    {
        $quantity = (float) $balances->sum(fn ($balance): float => (float) $balance->quantity);

        if ($quantity <= 0) {
            return 0.0;
        }

        $value = (float) $balances->sum(fn ($balance): float => (float) $balance->quantity * (float) $balance->average_cost);

        return $value / $quantity;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
};
