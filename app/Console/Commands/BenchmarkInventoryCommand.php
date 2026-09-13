<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryQueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchmarkInventoryCommand extends Command
{
    protected $signature = 'moscow-traders-wholesale:benchmark-inventory {--company= : Company UUID to benchmark} {--warehouse= : Warehouse UUID to benchmark}';

    protected $description = 'Benchmark core inventory queries.';

    public function handle(InventoryQueryService $inventoryQueryService): int
    {
        $company = $this->option('company')
            ? Company::query()->find($this->option('company'))
            : Company::query()->where('name', 'Moscow Traders Wholesale')->first();

        if (! $company) {
            $this->error('Benchmark company not found.');

            return self::FAILURE;
        }

        $warehouse = $this->option('warehouse')
            ? Warehouse::query()->where('company_id', $company->id)->find($this->option('warehouse'))
            : Warehouse::query()->where('company_id', $company->id)->where('code', 'MAIN-WH')->first();

        if (! $warehouse) {
            $this->error('Benchmark warehouse not found.');

            return self::FAILURE;
        }

        $productIds = Product::query()
            ->where('company_id', $company->id)
            ->where('track_inventory', true)
            ->orderBy('name')
            ->limit(100)
            ->pluck('id')
            ->all();

        if ($productIds === []) {
            $this->error('No inventory-tracked products found for the benchmark company.');

            return self::FAILURE;
        }

        $singleProductId = $productIds[0];

        $singleBalance = $this->profileQuery(fn () => $inventoryQueryService->getBalance($company->id, $warehouse->id, $singleProductId));
        $hundredBalances = $this->profileQuery(fn () => $inventoryQueryService->getBalancesForProducts($company->id, $warehouse->id, $productIds));
        $inventoryPage = $this->profileQuery(fn () => $inventoryQueryService->warehouseInventory($company->id, $warehouse->id, 50)->items());
        $lowStock = $this->profileQuery(fn () => $inventoryQueryService->lowStockByWarehouse($company->id, $warehouse->id)->limit(50)->get());
        $movementHistory = $this->profileQuery(fn () => $inventoryQueryService->movementHistory($company->id, ['warehouse_id' => $warehouse->id])->limit(50)->get());

        $this->table(
            ['Metric', 'Time', 'Queries'],
            [
                ['Company', $company->name, '-'],
                ['Warehouse', $warehouse->name, '-'],
                ['Single balance lookup', sprintf('%.3f ms', $singleBalance['time_ms']), (string) $singleBalance['query_count']],
                ['100-product balance lookup', sprintf('%.3f ms', $hundredBalances['time_ms']), (string) $hundredBalances['query_count']],
                ['Warehouse inventory pagination', sprintf('%.3f ms', $inventoryPage['time_ms']), (string) $inventoryPage['query_count']],
                ['Low-stock query', sprintf('%.3f ms', $lowStock['time_ms']), (string) $lowStock['query_count']],
                ['Movement-history query', sprintf('%.3f ms', $movementHistory['time_ms']), (string) $movementHistory['query_count']],
            ]
        );

        $inventoryQueries = $inventoryPage['queries'] ?? [];

        if ($inventoryQueries !== []) {
            $this->line('Inventory pagination COUNT SQL: '.$inventoryQueries[0]['query']);
            if (isset($inventoryQueries[1])) {
                $this->line('Inventory pagination page SQL: '.$inventoryQueries[1]['query']);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{time_ms:float, query_count:int, queries:array<int, array<string, mixed>>}
     */
    private function profileQuery(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);
        $callback();
        $queries = DB::getQueryLog();

        return [
            'time_ms' => (hrtime(true) - $start) / 1_000_000,
            'query_count' => count($queries),
            'queries' => $queries,
        ];
    }
}
