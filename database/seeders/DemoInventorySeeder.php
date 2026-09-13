<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class DemoInventorySeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(InventoryService $inventoryService): void
    {
        $company = Company::query()->where('name', 'Moscow Traders Wholesale')->firstOrFail();
        $warehouse = Warehouse::query()
            ->where('company_id', $company->id)
            ->where('code', 'MAIN-WH')
            ->firstOrFail();

        $openingStock = [
            'COKE-500' => ['quantity' => 100, 'unit_cost' => 8.5000],
            'WATER-1500' => ['quantity' => 80, 'unit_cost' => 4.0000],
            'PEPSI-500' => ['quantity' => 75, 'unit_cost' => 8.0000],
            'NOODLES-001' => ['quantity' => 60, 'unit_cost' => 5.2500],
            'DISH-500' => ['quantity' => 30, 'unit_cost' => 14.0000],
            'GALAXY-A16' => ['quantity' => 8, 'unit_cost' => 2300],
            'AIR-M2-13' => ['quantity' => 3, 'unit_cost' => 12800],
            'JBL-GO4' => ['quantity' => 12, 'unit_cost' => 650],
            'MI-PB-20K' => ['quantity' => 15, 'unit_cost' => 480],
            'LOGI-G203' => ['quantity' => 10, 'unit_cost' => 390],
            'GALAXY-FIT3' => ['quantity' => 7, 'unit_cost' => 750],
        ];

        foreach ($openingStock as $sku => $data) {
            $product = Product::query()
                ->where('company_id', $company->id)
                ->where('sku', $sku)
                ->first();

            if (! $product) {
                continue;
            }

            $hasOpening = $product->stockMovements()
                ->where('company_id', $company->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('type', StockMovementType::Opening)
                ->exists();

            if ($hasOpening) {
                continue;
            }

            $inventoryService->setOpeningStock(
                $company->id,
                $warehouse->id,
                $product->id,
                $data['quantity'],
                $data['unit_cost'],
                null,
                now(),
                'Seeded opening stock'
            );
        }
    }
}
