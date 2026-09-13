<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ReceiptPrintEvent;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSaleReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_reprints_are_watermarked_and_audited_sequentially(): void
    {
        $warehouse = Warehouse::factory()->create();
        $admin = User::factory()->forWarehouse($warehouse)->create();
        $admin->assignRole(Role::findByName('admin'));
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'selling_price' => 10,
            'tax_rate' => 0,
            'track_inventory' => false,
        ]);
        $sale = app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [['product_id' => $product->id, 'quantity' => 1]],
            [['payment_method' => 'cash', 'amount' => 10]],
            ['created_by' => $admin->id],
        );

        $this->actingAs($admin)
            ->get(route('admin.sales.receipt', ['sale' => $sale, 'format' => 'thermal']))
            ->assertOk()
            ->assertSee('REPRINT #1')
            ->assertSee($sale->sale_number);
        $this->actingAs($admin)
            ->get(route('admin.sales.receipt', ['sale' => $sale, 'format' => 'a4']))
            ->assertOk()
            ->assertSee('REPRINT #2')
            ->assertSee('TAX INVOICE');

        $this->assertSame(2, ReceiptPrintEvent::query()->where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('receipt_print_events', ['sale_id' => $sale->id, 'reprint_number' => 2, 'format' => 'a4']);
    }

    public function test_credit_invoice_snapshots_customer_gst_terms_due_date_and_unpaid_status(): void
    {
        $warehouse = Warehouse::factory()->create();
        $admin = User::factory()->forWarehouse($warehouse)->create();
        $admin->assignRole(Role::findByName('admin'));
        $customer = Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'name' => 'Wholesale Customer',
            'tax_number' => '1019999GST501',
            'payment_terms_days' => 15,
            'credit_limit' => null,
        ]);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'selling_price' => 100,
            'tax_rate' => 0,
            'track_inventory' => false,
        ]);

        $sale = app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [['product_id' => $product->id, 'quantity' => 1]],
            [],
            [
                'customer_id' => $customer->id,
                'created_by' => $admin->id,
                'sale_date' => '2026-09-13',
            ],
        );

        $this->assertSame('1019999GST501', $sale->customer_tax_number);
        $this->assertSame(15, $sale->payment_terms_days);
        $this->assertSame('2026-09-28', $sale->due_date?->toDateString());

        $this->actingAs($admin)
            ->get(route('admin.sales.receipt', ['sale' => $sale, 'format' => 'a4']))
            ->assertOk()
            ->assertSee('NOT PAID')
            ->assertSee('Customer GST No.: 1019999GST501')
            ->assertSee('Terms: Due 15')
            ->assertSee('Due date: 28 Sep 2026')
            ->assertSee('Balance due');
    }

    public function test_cancelled_held_sale_receipt_has_no_amount_due(): void
    {
        $warehouse = Warehouse::factory()->create();
        $admin = User::factory()->forWarehouse($warehouse)->create();
        $admin->assignRole(Role::findByName('admin'));
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'selling_price' => 125,
            'tax_rate' => 0,
            'track_inventory' => false,
        ]);
        $sale = app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [['product_id' => $product->id, 'quantity' => 1]],
            [],
            [
                'created_by' => $admin->id,
                'status' => SaleStatus::Held,
            ],
        );

        $sale = app(SalesService::class)->cancelHeldSale(
            $sale->id,
            $warehouse->company_id,
            'Price enquiry only',
            'Customer did not proceed.',
            $admin->id,
        );

        $this->assertSame('0.0000', $sale->balance_due);
        $this->assertNull($sale->due_date);

        $this->actingAs($admin)
            ->get(route('admin.sales.receipt', ['sale' => $sale, 'format' => 'a4']))
            ->assertOk()
            ->assertSee('CANCELLED SALE')
            ->assertSee('CANCELLED')
            ->assertSee('Cancellation reason:')
            ->assertSee('Price enquiry only')
            ->assertSee('No payment required — cancelled')
            ->assertDontSee('Balance due')
            ->assertDontSee('NOT PAID');
    }
}
