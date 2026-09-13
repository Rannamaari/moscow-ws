<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Enums\TaxCategory;
use App\Models\Product;
use App\Models\ProductBranchPrice;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\MiraGstReportService;
use App\Services\PurchaseService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GstReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mira_report_calculates_output_input_and_product_categories(): void
    {
        $warehouse = Warehouse::factory()->create();
        $company = $warehouse->company;
        $company->update([
            'tax_number' => '1010153GST501',
            'default_tax_rate' => 8,
            'gst_filing_frequency' => 'quarterly',
        ]);
        $standardProduct = Product::factory()->create(['company_id' => $company->id]);
        $zeroRatedProduct = Product::factory()->create([
            'company_id' => $company->id,
            'tax_category' => TaxCategory::ZeroRated,
        ]);
        $sale = Sale::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'MVR',
            'status' => SaleStatus::Completed,
            'sale_date' => '2026-07-15',
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'company_id' => $company->id,
            'product_id' => $standardProduct->id,
            'tax_category' => TaxCategory::StandardRated,
            'tax_rate' => 8,
            'tax_amount' => 8,
            'line_total' => 108,
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'company_id' => $company->id,
            'product_id' => $zeroRatedProduct->id,
            'tax_category' => TaxCategory::ZeroRated,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 50,
        ]);

        $supplier = Supplier::factory()->create(['company_id' => $company->id, 'tax_number' => '1000000GST001']);
        $purchase = Purchase::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'currency' => 'MVR',
            'status' => PurchaseStatus::Received,
            'purchase_date' => '2026-07-10',
            'supplier_invoice_number' => 'SUP-INV-1',
        ]);
        PurchaseItem::factory()->create([
            'purchase_id' => $purchase->id,
            'company_id' => $company->id,
            'product_id' => $standardProduct->id,
            'tax_category' => TaxCategory::StandardRated,
            'tax_rate' => 8,
            'tax_amount' => 8,
            'line_total' => 108,
            'input_tax_claimable' => true,
        ]);

        $report = app(MiraGstReportService::class)->report($company->id, '2026-07-01', '2026-09-30');

        $this->assertSame('1010153GST501', $report['company']->tax_number);
        $this->assertSame(108.0, $report['summary']['box_1_standard_rated_sales_inclusive']);
        $this->assertSame(50.0, $report['summary']['box_2_zero_rated_sales']);
        $this->assertSame(158.0, $report['summary']['box_5_total_sales']);
        $this->assertSame(8.0, $report['summary']['box_6_output_tax']);
        $this->assertSame(8.0, $report['summary']['box_7_input_tax']);
        $this->assertSame(0.0, $report['summary']['box_10_gst_liability']);
    }

    public function test_tax_inclusive_purchase_cost_extracts_gst_without_adding_it_again(): void
    {
        $warehouse = Warehouse::factory()->create();
        $warehouse->company->update(['default_tax_rate' => 8]);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $product = Product::factory()->create(['company_id' => $warehouse->company_id]);

        $purchase = app(PurchaseService::class)->createPurchase(
            $warehouse->company_id,
            $warehouse->id,
            $supplier->id,
            [[
                'product_id' => $product->id,
                'ordered_quantity' => 1,
                'unit_cost' => 108,
                'tax_category' => TaxCategory::StandardRated->value,
                'tax_rate' => 8,
                'price_includes_tax' => true,
                'input_tax_claimable' => true,
            ]],
            ['status' => PurchaseStatus::Ordered, 'branch_id' => $warehouse->branch_id],
        );

        $this->assertSame('108.0000', $purchase->grand_total);
        $this->assertSame('8.0000', $purchase->tax_total);
        $this->assertTrue($purchase->items->first()->price_includes_tax);
        $this->assertTrue($purchase->items->first()->input_tax_claimable);

        app(PurchaseService::class)->receivePurchase($purchase->id, [$purchase->items->first()->id => 1]);

        $this->assertSame('100.0000', ProductBranchPrice::query()
            ->where('branch_id', $warehouse->branch_id)
            ->where('product_id', $product->id)
            ->value('cost_price'));
    }

    public function test_admin_can_open_mira_gst_reports_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $warehouse = Warehouse::factory()->create();
        $admin = User::factory()->forWarehouse($warehouse)->create();
        $admin->assignRole(Role::findByName('admin'));

        $this->actingAs($admin)
            ->get('/admin/gst-reports')
            ->assertOk()
            ->assertSee('MIRA GST Reports')
            ->assertSee('Input Tax Statement')
            ->assertSee('Output Tax Statement');

        $this->actingAs($admin)
            ->get('/admin/receipt-settings')
            ->assertOk()
            ->assertSee('GST Registration Number')
            ->assertSee('GST Filing Frequency');
    }
}
