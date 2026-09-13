<?php

namespace Database\Seeders;

use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SalesService;
use Illuminate\Database\Seeder;

class DemoTradeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(PurchaseService $purchaseService, SalesService $salesService): void
    {
        $company = Company::query()->where('name', 'Moscow Traders Wholesale')->firstOrFail();
        $warehouse = Warehouse::query()
            ->where('company_id', $company->id)
            ->where('code', 'MAIN-WH')
            ->firstOrFail();

        $suppliers = [
            ['code' => 'SUP-001', 'name' => 'Demo Wholesale Supplier'],
            ['code' => 'SUP-002', 'name' => 'Wholesale Distributors'],
            ['code' => 'SUP-003', 'name' => 'General Trading Supplier'],
        ];

        foreach ($suppliers as $supplierData) {
            Supplier::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $supplierData['code']],
                [
                    'name' => $supplierData['name'],
                    'is_active' => true,
                ]
            );
        }

        $walkIn = Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'WALK-IN'],
            [
                'name' => 'Walk-in Customer',
                'credit_limit' => null,
                'is_walk_in' => true,
                'is_active' => true,
            ]
        );

        Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'CUS-001'],
            [
                'name' => 'Demo Customer',
                'credit_limit' => 1000,
                'is_walk_in' => false,
                'is_active' => true,
            ]
        );

        Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'CUS-002'],
            [
                'name' => 'Corporate Customer',
                'credit_limit' => 5000,
                'is_walk_in' => false,
                'is_active' => true,
            ]
        );

        if (! Purchase::query()->where('company_id', $company->id)->where('purchase_number', 'PUR-DEMO-0001')->exists()) {
            $supplier = Supplier::query()->where('company_id', $company->id)->where('code', 'SUP-001')->firstOrFail();
            $product = Product::query()->where('company_id', $company->id)->where('sku', 'COKE-500')->firstOrFail();

            $purchase = $purchaseService->createPurchase($company->id, $warehouse->id, $supplier->id, [
                [
                    'product_id' => $product->id,
                    'ordered_quantity' => 10,
                    'unit_cost' => 8.7500,
                    'tax_rate' => 0,
                ],
            ], [
                'branch_id' => $warehouse->branch_id,
                'purchase_number' => 'PUR-DEMO-0001',
                'purchase_date' => '2026-08-14',
                'status' => PurchaseStatus::Ordered,
                'notes' => 'Seeded demo purchase',
            ]);

            $purchaseService->receivePurchase($purchase->id, [
                $purchase->items->firstOrFail()->id => 10,
            ]);
        }

        if (! Sale::query()->where('company_id', $company->id)->where('sale_number', 'SAL-DEMO-0001')->exists()) {
            $product = Product::query()->where('company_id', $company->id)->where('sku', 'WATER-1500')->firstOrFail();

            $salesService->createSale($company->id, $warehouse->branch_id, $warehouse->id, [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ], [
                [
                    'payment_method' => 'cash',
                    'amount' => 15,
                    'amount_tendered' => 15,
                ],
            ], [
                'customer_id' => $walkIn->id,
                'sale_number' => 'SAL-DEMO-0001',
                'sale_date' => '2026-08-14',
                'status' => SaleStatus::Completed,
                'notes' => 'Seeded demo sale',
            ]);
        }
    }
}
