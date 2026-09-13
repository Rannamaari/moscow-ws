<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DemoOrganizationSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = Company::query()->updateOrCreate(
            ['name' => 'Moscow Traders Wholesale'],
            [
                'legal_name' => 'Moscow Traders (Wholesale)',
                'receipt_shop_name' => 'Moscow Traders Wholesale',
                'timezone' => 'Indian/Maldives',
                'currency' => 'MVR',
                'default_tax_rate' => 0,
                'is_active' => true,
            ]
        );

        $branch = Branch::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'MAIN'],
            [
                'name' => 'Moscow Traders Wholesale',
                'city' => 'Male',
                'is_active' => true,
            ]
        );

        $warehouse = Warehouse::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'MAIN-WH'],
            [
                'branch_id' => $branch->id,
                'name' => 'Main Warehouse',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $company->update([
            'online_branch_id' => $branch->id,
            'online_warehouse_id' => $warehouse->id,
            'website_enabled' => true,
            'city' => null,
            'country' => 'Maldives',
            'website_delivery_methods' => ['pickup' => 'Store Pickup', 'local_delivery' => 'Local Delivery'],
            'website_payment_methods' => ['cash' => 'Cash / Pay on Collection', 'bank_transfer' => 'Bank Transfer'],
        ]);

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@moscowtraders.local'],
            [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'name' => 'Moscow Traders Admin',
                'password' => 'password',
                'is_active' => true,
            ]
        );

        $user->syncRoles(['super-admin']);

        $cashier = User::query()->updateOrCreate(
            ['email' => 'cashier@moscowtraders.local'],
            [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'name' => 'Moscow Traders Cashier',
                'password' => 'password',
                'is_active' => true,
            ]
        );

        $cashier->syncRoles(['cashier']);

        Customer::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'WALK-IN'],
            [
                'name' => 'Walk-in Customer',
                'credit_limit' => null,
                'is_walk_in' => true,
                'is_active' => true,
            ]
        );
    }
}
