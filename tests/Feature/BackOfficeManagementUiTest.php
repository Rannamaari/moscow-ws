<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackOfficeManagementUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function authorized_admin_can_open_back_office_dashboard(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function product_creation_can_record_opening_stock_for_its_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $unit = Unit::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateProduct::class)
            ->fillForm([
                'company_id' => $warehouse->company_id,
                'name' => 'Opening Stock Product',
                'sku' => 'OPEN-100',
                'unit_id' => $unit->id,
                'cost_price' => 8,
                'selling_price' => 12,
                'track_inventory' => true,
                'is_active' => true,
                'opening_warehouse_id' => $warehouse->id,
                'opening_quantity' => 14,
                'opening_unit_cost' => 7.5,
                'branchPrices' => [[
                    'branch_id' => $warehouse->branch_id,
                    'currency' => $warehouse->branch->currency,
                    'cost_price' => 8,
                    'selling_price' => 12,
                    'company_id' => $warehouse->company_id,
                ]],
                'barcodes' => [[
                    'barcode' => '1234567890123',
                    'is_primary' => true,
                    'company_id' => $warehouse->company_id,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/products');

        $product = Product::query()->where('sku', 'OPEN-100')->firstOrFail();
        $balance = InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $movement = StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('type', StockMovementType::Opening)
            ->firstOrFail();

        $this->assertSame('14.0000', $balance->quantity);
        $this->assertSame('14.0000', $movement->quantity);
        $this->assertSame('7.5000', $movement->unit_cost);
    }

    #[Test]
    public function admin_navigation_uses_dhivehi_after_switching_locale(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);

        $this->actingAs($user)->post('/locale/dv')->assertRedirect();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('ޑޭޝްބޯޑް')
            ->assertSee('ކެޓަލޮގް');
    }

    #[Test]
    public function authorized_admin_can_open_branch_receipt_settings(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@moscowtraders.local')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/receipt-settings')
            ->assertOk()
            ->assertSee('Business Settings')
            ->assertSee('TEST on POS');
    }

    #[Test]
    public function only_super_admin_can_open_test_data_reset(): void
    {
        $warehouse = Warehouse::factory()->create();
        $superAdmin = $this->userWithRole('super-admin', $warehouse);
        $admin = $this->userWithRole('admin', $warehouse);

        $this->actingAs($superAdmin)->get('/admin/transaction-data-reset')->assertOk();
        $this->actingAs($admin)->get('/admin/transaction-data-reset')->assertForbidden();
    }

    #[Test]
    public function cashier_cannot_access_back_office_panel(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('cashier', $warehouse);

        $response = $this->actingAs($user)->get('/admin');

        $this->assertTrue(in_array($response->status(), [302, 403], true));
    }

    #[Test]
    public function product_management_page_is_company_scoped(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);

        $visibleProduct = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Visible Admin Product',
            'sku' => 'ADM-100',
        ]);

        $otherCompany = Company::factory()->create();
        Product::factory()->create([
            'company_id' => $otherCompany->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Other Company Product',
            'sku' => 'OTH-100',
        ]);

        $this->actingAs($user)
            ->get('/admin/products')
            ->assertOk()
            ->assertSee($visibleProduct->name)
            ->assertDontSee('Other Company Product');
    }

    #[Test]
    public function purchase_orders_page_is_accessible_and_company_scoped(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $purchase = Purchase::factory()->create([
            'company_id' => $warehouse->company_id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => 'PO-1001',
        ]);

        $otherWarehouse = Warehouse::factory()->create();
        $otherSupplier = Supplier::factory()->create(['company_id' => $otherWarehouse->company_id]);
        Purchase::factory()->create([
            'company_id' => $otherWarehouse->company_id,
            'branch_id' => $otherWarehouse->branch_id,
            'warehouse_id' => $otherWarehouse->id,
            'supplier_id' => $otherSupplier->id,
            'purchase_number' => 'PO-OTHER-1',
        ]);

        $this->actingAs($user)
            ->get('/admin/purchases')
            ->assertOk()
            ->assertSee($purchase->purchase_number)
            ->assertDontSee('PO-OTHER-1');
    }

    #[Test]
    public function authorized_admin_can_open_new_purchase_order_page(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);

        $this->actingAs($user)
            ->get('/admin/purchases/create')
            ->assertOk()
            ->assertSee('Purchase Order');
    }

    #[Test]
    public function purchase_order_form_previews_line_and_grand_totals_from_entered_values(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'cost_price' => 100,
            'tax_category' => 'standard_rated',
        ]);

        Livewire::actingAs($user)
            ->test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => 'ordered',
                'purchase_date' => now()->toDateString(),
                'shipping_total' => 5,
                'other_cost_total' => 3,
                'items' => [[
                    'product_id' => $product->id,
                    'ordered_quantity' => 2,
                    'unit_cost' => 100,
                    'discount_amount' => 0,
                    'tax_rate' => 8,
                    'tax_category' => 'standard_rated',
                    'price_includes_tax' => false,
                    'input_tax_claimable' => true,
                ]],
            ])
            ->assertSee('216.00')
            ->assertSee('224.0000');
    }

    #[Test]
    public function purchase_order_view_shows_the_automatically_calculated_total_gst(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'cost_price' => 100,
            'tax_category' => 'standard_rated',
        ]);
        $purchase = app(PurchaseService::class)->createPurchase(
            $warehouse->company_id,
            $warehouse->id,
            $supplier->id,
            [[
                'product_id' => $product->id,
                'ordered_quantity' => 2,
                'unit_cost' => 100,
                'discount_amount' => 0,
                'tax_rate' => 8,
                'tax_category' => 'standard_rated',
                'price_includes_tax' => false,
                'input_tax_claimable' => true,
            ]],
            ['branch_id' => $warehouse->branch_id],
        );

        $this->assertSame('16.0000', $purchase->tax_total);

        Livewire::actingAs($user)
            ->test(ViewPurchase::class, ['record' => $purchase->id])
            ->assertSee('Purchase Totals')
            ->assertSee('Automatically calculated from the saved purchase lines.')
            ->assertSee('Total GST')
            ->assertSee('16.00');
    }

    #[Test]
    public function purchase_can_be_saved_and_received_into_inventory_in_one_step(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Immediate Receipt Product',
            'track_inventory' => true,
            'tax_category' => 'exempt',
        ]);

        Livewire::actingAs($user)
            ->test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => 'receive_now',
                'purchase_date' => now()->toDateString(),
                'shipping_total' => 0,
                'other_cost_total' => 0,
                'items' => [[
                    'product_id' => $product->id,
                    'ordered_quantity' => 3,
                    'unit_cost' => 10,
                    'discount_amount' => 0,
                    'tax_rate' => 0,
                    'tax_category' => 'exempt',
                    'price_includes_tax' => false,
                    'input_tax_claimable' => false,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->latest('created_at')->firstOrFail();

        $this->assertSame('received', $purchase->status->value);
        $this->assertSame('3.0000', $purchase->items->firstOrFail()->received_quantity);
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '3.0000',
        ]);
        Livewire::actingAs($user)
            ->test(ViewPurchase::class, ['record' => $purchase->id])
            ->assertSee('Immediate Receipt Product')
            ->assertSee('Fully received into inventory.');
    }

    #[Test]
    public function purchase_edit_form_loads_saved_items_and_saves_a_corrected_unit_cost(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Price Correction Product',
            'tax_category' => 'exempt',
        ]);
        $purchase = app(PurchaseService::class)->createPurchase(
            $warehouse->company_id,
            $warehouse->id,
            $supplier->id,
            [[
                'product_id' => $product->id,
                'ordered_quantity' => 1,
                'unit_cost' => 46.30,
                'tax_category' => 'exempt',
            ]],
            ['branch_id' => $warehouse->branch_id],
        );

        $component = Livewire::actingAs($user)->test(EditPurchase::class, ['record' => $purchase->id]);
        $items = $component->get('data.items');
        $itemKey = array_key_first($items);

        $this->assertCount(1, $items);
        $this->assertSame($product->id, $items[$itemKey]['product_id']);
        $this->assertSame(46.3, $items[$itemKey]['unit_cost']);

        $component
            ->set("data.items.{$itemKey}.unit_cost", 25)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('25.0000', $purchase->fresh()->subtotal);
        $this->assertSame('25.0000', $purchase->items()->firstOrFail()->unit_cost);
    }

    #[Test]
    public function product_creation_page_shows_the_latest_company_product_and_sku(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Latest Catalog Product',
            'sku' => 'LATEST-001',
        ]);

        Product::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Other Company Product',
            'sku' => 'OTHER-001',
        ]);

        $this->actingAs($user)
            ->get('/admin/products/create')
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('SKU: '.$product->sku)
            ->assertDontSee('Other Company Product');
    }

    #[Test]
    public function product_can_be_updated_without_changing_its_unique_sku(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'sku' => 'ONLINE-PRICE-001',
            'sale_price' => null,
        ]);

        Livewire::actingAs($user)
            ->test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['sale_price' => 125])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ONLINE-PRICE-001', $product->fresh()->sku);
        $this->assertSame('125.0000', $product->fresh()->sale_price);
    }

    #[Test]
    public function product_update_still_rejects_another_products_sku(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $unit = Unit::factory()->create();
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => $unit->id,
            'sku' => 'EDITABLE-001',
        ]);
        Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => $unit->id,
            'sku' => 'TAKEN-001',
        ]);

        Livewire::actingAs($user)
            ->test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['sku' => 'TAKEN-001'])
            ->call('save')
            ->assertHasFormErrors(['sku' => 'unique']);

        $this->assertSame('EDITABLE-001', $product->fresh()->sku);
    }

    #[Test]
    public function product_image_uploads_are_optimized_before_storage(): void
    {
        Storage::fake('public');
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'sku' => 'IMAGE-001',
        ]);

        Livewire::actingAs($user)
            ->test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['images' => [UploadedFile::fake()->image('product.jpg', 1800, 900)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $product->fresh()->images[0];
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.webp', $path);

        $dimensions = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertIsArray($dimensions);
        $this->assertSame(1200, $dimensions[0]);
        $this->assertSame(1200, $dimensions[1]);
    }

    #[Test]
    public function purchase_receive_action_uses_fresh_remaining_quantities(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = $this->userWithRole('admin', $warehouse);
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Cola',
            'sku' => 'COLA-1',
        ]);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $purchase = app(PurchaseService::class)->createPurchase(
            $warehouse->company_id,
            $warehouse->id,
            $supplier->id,
            [
                ['product_id' => $product->id, 'ordered_quantity' => 10, 'unit_cost' => 8.5],
            ],
            ['branch_id' => $warehouse->branch_id],
        );

        $component = Livewire::actingAs($user)->test(ViewPurchase::class, ['record' => $purchase->id]);

        app(PurchaseService::class)->receivePurchase($purchase->id, [
            $purchase->items->firstOrFail()->id => 6,
        ], $user->id);

        /** @var PurchaseItem $purchaseItem */
        $purchaseItem = $purchase->items->firstOrFail()->fresh();

        $component
            ->callAction('receive_items', data: [
                'items' => [[
                    'purchase_item_id' => $purchaseItem->id,
                    'receive_now' => '4.0000',
                ]],
                'received_at' => now()->toDateString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('purchase_items', [
            'id' => $purchaseItem->id,
            'received_quantity' => '10.0000',
        ]);
    }

    private function userWithRole(string $role, Warehouse $warehouse): User
    {
        $user = User::factory()->forWarehouse($warehouse)->create();
        $user->assignRole(Role::findByName($role, 'web'));

        return $user;
    }
}
