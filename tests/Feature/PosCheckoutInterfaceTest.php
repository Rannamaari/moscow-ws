<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\SalesService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCheckoutInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function authorized_cashier_can_open_pos_and_guest_cannot(): void
    {
        $warehouse = Warehouse::factory()->create();
        $cashier = $this->userWithRole('cashier', $warehouse);
        Customer::factory()->walkIn()->create(['company_id' => $warehouse->company_id]);

        $this->get('/pos')->assertRedirect('/login');

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertSee('pos-app', false)
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('"can_access_admin":false', false)
            ->assertSee('Moscow Traders Wholesale');
    }

    #[Test]
    public function cashier_can_switch_the_pos_to_dhivehi_with_rtl_layout(): void
    {
        $warehouse = Warehouse::factory()->create();
        $cashier = $this->userWithRole('cashier', $warehouse);
        Customer::factory()->walkIn()->create(['company_id' => $warehouse->company_id]);

        $this->actingAs($cashier)
            ->post('/locale/dv')
            ->assertRedirect();

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertSee('dir="rtl"', false);

        $this->assertSame('dv', app()->getLocale());
        $this->assertSame('ޝިފްޓް އޯޕަން', __('pos.open_shift'));
    }

    #[Test]
    public function admin_pos_users_receive_the_back_office_link_flag(): void
    {
        $warehouse = Warehouse::factory()->create();
        $admin = $this->userWithRole('admin', $warehouse);
        Customer::factory()->walkIn()->create(['company_id' => $warehouse->company_id]);

        $this->actingAs($admin)
            ->get('/pos')
            ->assertOk()
            ->assertSee('"can_access_admin":true', false);
    }

    #[Test]
    public function user_without_sales_permission_cannot_open_pos(): void
    {
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->forWarehouse($warehouse)->create();

        $this->actingAs($user)
            ->get('/pos')
            ->assertForbidden();
    }

    #[Test]
    public function barcode_endpoint_is_company_scoped_and_includes_stock(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();
        ProductBarcode::factory()->create([
            'company_id' => $warehouse->company_id,
            'product_id' => $product->id,
            'barcode' => '8901234567890',
            'is_primary' => true,
        ]);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 12, 8);

        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $otherProduct = Product::factory()->create([
            'company_id' => $otherCompany->id,
            'unit_id' => Unit::factory()->create()->id,
        ]);
        ProductBarcode::factory()->create([
            'company_id' => $otherCompany->id,
            'product_id' => $otherProduct->id,
            'barcode' => '8901234567890',
            'is_primary' => true,
        ]);

        $cashier = $this->userWithRole('cashier', $warehouse);

        $this->actingAs($cashier)
            ->getJson('/pos/api/products/barcode/8901234567890')
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.stock', '12.0000')
            ->assertJsonPath('data.unit.precision', 0);
    }

    #[Test]
    public function manual_product_search_excludes_inactive_products(): void
    {
        [$warehouse] = $this->warehouseAndProduct();
        Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Active Coke',
            'sku' => 'AC-100',
            'is_active' => true,
        ]);
        Product::factory()->inactive()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'Inactive Coke',
            'sku' => 'IC-100',
        ]);

        $cashier = $this->userWithRole('cashier', $warehouse);

        $response = $this->actingAs($cashier)
            ->getJson('/pos/api/products/search?q=coke')
            ->assertOk()
            ->json('data');

        $names = collect($response)->pluck('name');

        $this->assertTrue($names->contains('Active Coke'));
        $this->assertFalse($names->contains('Inactive Coke'));
    }

    #[Test]
    public function customer_search_is_company_scoped_and_walk_in_is_available(): void
    {
        $warehouse = Warehouse::factory()->create();
        $walkIn = Customer::factory()->walkIn()->create(['company_id' => $warehouse->company_id]);
        Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'name' => 'Demo Customer',
            'code' => 'CUS-1234',
        ]);
        Customer::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'name' => 'Other Customer',
            'code' => 'CUS-1234',
        ]);

        $cashier = $this->userWithRole('cashier', $warehouse);

        $response = $this->actingAs($cashier)
            ->getJson('/pos/api/customers/search?q=CUS-1234')
            ->assertOk()
            ->json('data');

        $names = collect($response)->pluck('name');
        $ids = collect($response)->pluck('id');

        $this->assertTrue($names->contains('Demo Customer'));
        $this->assertFalse($names->contains('Other Customer'));
        $this->assertFalse($ids->contains($walkIn->id));

        $all = $this->actingAs($cashier)
            ->getJson('/pos/api/customers/search')
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($all)->pluck('id')->contains($walkIn->id));
    }

    #[Test]
    public function authorized_cashier_can_create_quick_customer(): void
    {
        $warehouse = Warehouse::factory()->create();
        $cashier = $this->userWithRole('cashier', $warehouse);

        $this->actingAs($cashier)
            ->postJson('/pos/api/customers', [
                'name' => 'New Counter Customer',
                'phone' => '7771234',
                'tax_number' => '1019999GST501',
                'payment_terms_days' => 15,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Counter Customer')
            ->assertJsonPath('data.tax_number', '1019999GST501')
            ->assertJsonPath('data.payment_terms_days', 15);

        $this->assertDatabaseHas('customers', [
            'company_id' => $warehouse->company_id,
            'name' => 'New Counter Customer',
            'tax_number' => '1019999GST501',
            'payment_terms_days' => 15,
        ]);
    }

    #[Test]
    public function complete_sale_endpoint_is_idempotent_accepts_mixed_payments_and_reduces_inventory_once(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        ProductBarcode::factory()->create([
            'company_id' => $warehouse->company_id,
            'product_id' => $product->id,
            'barcode' => '1002003004005',
            'is_primary' => true,
        ]);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);

        $cashier = $this->userWithRole('cashier', $warehouse);
        $this->openShift($cashier);

        $payload = [
            'client_transaction_uuid' => 'pos-sale-1001',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
            'payments' => [
                [
                    'payment_method' => 'cash',
                    'amount' => 10,
                    'amount_tendered' => 15,
                ],
                [
                    'payment_method' => 'card',
                    'amount' => 10,
                    'reference' => 'CARD-001',
                ],
            ],
        ];

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', $payload)
            ->assertOk()
            ->assertJsonPath('data.payments.0.change_due', '5.0000')
            ->assertJsonPath('data.receipt.branch_name', $warehouse->branch->name);

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', $payload)
            ->assertOk();

        $this->assertDatabaseCount('sales', 1);
        $this->assertSame('8.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
    }

    #[Test]
    public function payment_completion_requires_an_open_cashier_shift(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 2, 6);
        $cashier = $this->userWithRole('cashier', $warehouse);

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'shift-required-1',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [['payment_method' => 'cash', 'amount' => 10]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.shift.0', 'Open a cashier shift before completing a sale.');

        $this->assertDatabaseCount('sales', 0);
    }

    #[Test]
    public function insufficient_stock_returns_structured_validation_error(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        ProductBarcode::factory()->create([
            'company_id' => $warehouse->company_id,
            'product_id' => $product->id,
            'barcode' => '1002003004006',
            'is_primary' => true,
        ]);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 3, 6);

        $cashier = $this->userWithRole('cashier', $warehouse);

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'pos-sale-stock-1',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 5],
                ],
                'payments' => [
                    ['payment_method' => 'cash', 'amount' => 50],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.stock.0', 'Insufficient stock for one or more items.')
            ->assertJsonPath('errors.stock_details.0.product_name', $product->name)
            ->assertJsonPath('errors.stock_details.0.available', '3.0000');
    }

    #[Test]
    public function credit_sale_requires_customer_and_walk_in_customer_cannot_receive_credit(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);
        $manager = $this->userWithRole('manager', $warehouse);
        $this->openShift($manager);
        $walkIn = Customer::factory()->walkIn()->create(['company_id' => $warehouse->company_id]);

        $this->actingAs($manager)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'credit-no-customer',
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payments' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.sale.0', 'Credit sales require a customer.');

        $this->actingAs($manager)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'credit-walk-in',
                'customer_id' => $walkIn->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
                'payments' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.sale.0', 'Walk-in customer cannot receive credit sales.');
    }

    #[Test]
    public function held_sale_can_be_resumed_and_completed_without_double_inventory_change(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);

        $cashier = $this->userWithRole('cashier', $warehouse);
        $this->openShift($cashier);
        $customer = Customer::factory()->create(['company_id' => $warehouse->company_id]);
        $extraProduct = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create([
                'precision' => 0,
                'short_name' => 'pkg',
            ])->id,
            'selling_price' => 5,
            'cost_price' => 2,
            'tax_rate' => 0,
            'track_inventory' => true,
            'allow_negative_stock' => false,
        ]);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $extraProduct->id, 12, 2);

        $holdResponse = $this->actingAs($cashier)
            ->postJson('/pos/api/sales/hold', [
                'client_transaction_uuid' => 'held-1',
                'customer_id' => $customer->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('10.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));

        $this->actingAs($cashier)
            ->getJson('/pos/api/held-sales')
            ->assertOk()
            ->assertJsonPath('data.0.id', $holdResponse['id']);

        $this->actingAs($cashier)
            ->getJson("/pos/api/sales/{$holdResponse['id']}/resume")
            ->assertOk()
            ->assertJsonPath('data.id', $holdResponse['id'])
            ->assertJsonPath('data.customer.id', $customer->id);

        $this->actingAs($cashier)
            ->postJson("/pos/api/sales/{$holdResponse['id']}/complete", [
                'customer_id' => $customer->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                    ['product_id' => $extraProduct->id, 'quantity' => 2],
                ],
                'payments' => [
                    ['payment_method' => 'cash', 'amount' => 20],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', '1.0000')
            ->assertJsonPath('data.items.1.product_id', $extraProduct->id)
            ->assertJsonPath('data.items.1.quantity', '2.0000');

        $this->assertSame('9.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
        $this->assertSame('10.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $extraProduct->id));
    }

    #[Test]
    public function held_sale_requires_a_saved_non_walk_in_customer(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);

        $cashier = $this->userWithRole('cashier', $warehouse);
        $walkIn = Customer::factory()->walkIn()->create(['company_id' => $warehouse->company_id]);
        $namedCustomer = Customer::factory()->create(['company_id' => $warehouse->company_id]);

        $basePayload = [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ];

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales/hold', [
                ...$basePayload,
                'client_transaction_uuid' => 'held-no-customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.customer_id.0', 'Select a saved customer before holding a sale.');

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales/hold', [
                ...$basePayload,
                'client_transaction_uuid' => 'held-walk-in',
                'customer_id' => $walkIn->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.customer_id.0', 'Select a saved customer before holding a sale.');

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales/hold', [
                ...$basePayload,
                'client_transaction_uuid' => 'held-named-customer',
                'customer_id' => $namedCustomer->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.customer.id', $namedCustomer->id);
    }

    #[Test]
    public function cashier_only_sees_their_own_held_sales_but_manager_can_cancel_and_view_cancelled_history(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        $cashier = $this->userWithRole('cashier', $warehouse);
        $otherCashier = $this->userWithRole('cashier', $warehouse);
        $manager = $this->userWithRole('manager', $warehouse);
        $customer = Customer::factory()->create(['company_id' => $warehouse->company_id]);

        $ownHeldSale = app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [['product_id' => $product->id, 'quantity' => 1]],
            [],
            [
                'client_transaction_uuid' => 'held-own-sale',
                'customer_id' => $customer->id,
                'status' => SaleStatus::Held,
                'created_by' => $cashier->id,
            ],
        );

        $otherHeldSale = app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [['product_id' => $product->id, 'quantity' => 1]],
            [],
            [
                'client_transaction_uuid' => 'held-other-sale',
                'customer_id' => $customer->id,
                'status' => SaleStatus::Held,
                'created_by' => $otherCashier->id,
            ],
        );

        $heldSales = $this->actingAs($cashier)
            ->getJson('/pos/api/held-sales')
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($heldSales)->pluck('id')->contains($ownHeldSale->id));
        $this->assertFalse(collect($heldSales)->pluck('id')->contains($otherHeldSale->id));

        $this->actingAs($cashier)
            ->postJson("/pos/api/sales/{$ownHeldSale->id}/cancel-held", [
                'reason' => 'Customer changed mind',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson("/pos/api/sales/{$ownHeldSale->id}/cancel-held", [
                'reason' => 'Other',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.notes.0', 'Notes are required when the cancellation reason is Other.');

        $this->actingAs($manager)
            ->postJson("/pos/api/sales/{$ownHeldSale->id}/cancel-held", [
                'reason' => 'Manager instruction',
                'notes' => 'Reviewed with customer at checkout.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.balance_due', '0.0000');

        $this->assertDatabaseHas('sales', [
            'id' => $ownHeldSale->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Manager instruction',
            'cancelled_by' => $manager->id,
            'paid_total' => 0,
            'balance_due' => 0,
            'due_date' => null,
        ]);

        $this->actingAs($cashier)
            ->getJson("/pos/api/sales/{$ownHeldSale->id}")
            ->assertNotFound();

        $this->actingAs($manager)
            ->getJson('/pos/api/sales?status=cancelled')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ownHeldSale->id);
    }

    #[Test]
    public function price_override_and_discount_require_permission(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);
        $cashier = $this->userWithRole('cashier', $warehouse);
        $manager = $this->userWithRole('manager', $warehouse);
        $this->openShift($manager);

        $basePayload = [
            'client_transaction_uuid' => 'override-check-1',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 12,
                    'discount_amount' => 1,
                ],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 11],
            ],
        ];

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', $basePayload)
            ->assertStatus(422)
            ->assertJsonPath('errors.price_override.0', 'Price override requires permission.');

        $this->actingAs($manager)
            ->postJson('/pos/api/sales', [
                ...$basePayload,
                'client_transaction_uuid' => 'override-check-2',
            ])
            ->assertOk();
    }

    #[Test]
    public function sale_return_endpoint_validates_quantity_and_restores_inventory(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);
        $manager = $this->userWithRole('manager', $warehouse);
        $this->openShift($manager);

        $saleData = $this->actingAs($manager)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'sale-return-test',
                'items' => [['product_id' => $product->id, 'quantity' => 3]],
                'payments' => [['payment_method' => 'cash', 'amount' => 30]],
            ])
            ->assertOk()
            ->json('data');

        $saleItemId = $saleData['items'][0]['id'];

        $this->actingAs($manager)
            ->postJson("/pos/api/sales/{$saleData['id']}/returns", [
                'items' => [
                    ['sale_item_id' => $saleItemId, 'quantity' => 4],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.sale.0', 'Cannot return more than the quantity originally sold.');

        $this->actingAs($manager)
            ->postJson("/pos/api/sales/{$saleData['id']}/returns", [
                'items' => [
                    ['sale_item_id' => $saleItemId, 'quantity' => 2],
                ],
            ])
            ->assertOk();

        $this->assertSame('9.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
    }

    #[Test]
    public function cross_company_product_access_is_rejected_in_pos_checkout(): void
    {
        $warehouse = Warehouse::factory()->create();
        $otherWarehouse = Warehouse::factory()->create();
        $cashier = $this->userWithRole('cashier', $warehouse);
        $foreignProduct = Product::factory()->create([
            'company_id' => $otherWarehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
        ]);

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'cross-company-1',
                'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
                'payments' => [['payment_method' => 'cash', 'amount' => 10]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function authorized_cashier_can_access_sales_history_and_unauthorized_user_cannot(): void
    {
        $warehouse = Warehouse::factory()->create();
        $cashier = $this->userWithRole('cashier', $warehouse);
        $user = User::factory()->forWarehouse($warehouse)->create();

        $this->actingAs($cashier)
            ->getJson('/pos/api/sales')
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/pos/api/sales')
            ->assertForbidden();
    }

    #[Test]
    public function sales_history_is_company_scoped_and_defaults_to_today(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 50, 6);
        $cashier = $this->userWithRole('cashier', $warehouse);

        $todaySale = $this->createCompletedSale($warehouse, $product, $cashier, [
            'sale_number' => 'SAL-TODAY-001',
            'sale_date' => '2026-08-14',
            'completed_at' => '2026-08-14 10:30:00',
        ]);

        $yesterdaySale = $this->createCompletedSale($warehouse, $product, $cashier, [
            'sale_number' => 'SAL-YDAY-001',
            'sale_date' => '2026-08-13',
            'completed_at' => '2026-08-13 11:00:00',
            'client_transaction_uuid' => 'sale-yesterday-001',
        ]);

        $otherCashierAtSameStore = $this->userWithRole('cashier', $warehouse);
        $otherCashierSale = $this->createCompletedSale($warehouse, $product, $otherCashierAtSameStore, [
            'sale_number' => 'SAL-OTHER-CASHIER-001',
            'sale_date' => '2026-08-14',
            'completed_at' => '2026-08-14 10:45:00',
            'client_transaction_uuid' => 'sale-other-cashier-001',
        ]);

        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $otherCashier = $this->userWithRole('cashier', $otherWarehouse);
        $otherProduct = Product::factory()->create([
            'company_id' => $otherCompany->id,
            'unit_id' => Unit::factory()->create(['precision' => 0])->id,
            'selling_price' => 12,
            'cost_price' => 5,
        ]);
        app(InventoryService::class)->setOpeningStock($otherWarehouse->company_id, $otherWarehouse->id, $otherProduct->id, 20, 5);
        $this->createCompletedSale($otherWarehouse, $otherProduct, $otherCashier, [
            'sale_number' => 'SAL-OTHER-001',
            'sale_date' => '2026-08-14',
            'completed_at' => '2026-08-14 09:00:00',
        ]);

        $response = $this->actingAs($cashier)
            ->getJson('/pos/api/sales')
            ->assertOk()
            ->json();

        $saleNumbers = collect($response['data'])->pluck('sale_number');

        $this->assertTrue($saleNumbers->contains($todaySale->sale_number));
        $this->assertFalse($saleNumbers->contains($yesterdaySale->sale_number));
        $this->assertFalse($saleNumbers->contains($otherCashierSale->sale_number));
        $this->assertFalse($saleNumbers->contains('SAL-OTHER-001'));
        $this->assertSame('today', $response['filters']['period']);
        $this->assertSame('2026-08-14', $response['filters']['date_from']);
        $this->assertSame('2026-08-14', $response['filters']['date_to']);
    }

    #[Test]
    public function sales_history_supports_search_and_filters_and_is_paginated(): void
    {
        Carbon::setTestNow('2026-08-14 12:00:00');

        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 200, 6);
        $cashier = $this->userWithRole('cashier', $warehouse);
        $manager = $this->userWithRole('manager', $warehouse);
        $customer = Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'name' => 'Aisha Customer',
            'phone' => '7771000',
        ]);

        $cashSale = $this->createCompletedSale($warehouse, $product, $cashier, [
            'sale_number' => 'SAL-HISTORY-100',
            'sale_date' => '2026-08-14',
            'completed_at' => '2026-08-14 08:00:00',
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'amount_tendered' => 15,
        ]);

        $cardSale = $this->createCompletedSale($warehouse, $product, $manager, [
            'sale_number' => 'SAL-HISTORY-101',
            'sale_date' => '2026-08-14',
            'completed_at' => '2026-08-14 09:00:00',
            'payment_method' => 'card',
            'reference' => 'CARD-HISTORY-1',
        ]);

        $heldSale = app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [['product_id' => $product->id, 'quantity' => 1]],
            [],
            [
                'client_transaction_uuid' => 'held-history-sale',
                'status' => SaleStatus::Held,
                'created_by' => $cashier->id,
            ],
        );

        foreach (range(1, 28) as $index) {
            $this->createCompletedSale($warehouse, $product, $cashier, [
                'sale_number' => sprintf('SAL-PAGE-%03d', $index),
                'sale_date' => '2026-08-14',
                'completed_at' => sprintf('2026-08-14 10:%02d:00', $index % 60),
                'client_transaction_uuid' => sprintf('sale-page-%03d', $index),
            ]);
        }

        $this->actingAs($cashier)
            ->getJson('/pos/api/sales?search=SAL-HISTORY-100')
            ->assertOk()
            ->assertJsonPath('data.0.sale_number', $cashSale->sale_number)
            ->assertJsonPath('data.0.branch', $warehouse->branch->name);

        $customerSearch = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?search=Aisha')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($customerSearch)->pluck('sale_number')->contains($cashSale->sale_number));

        $phoneSearch = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?search=7771000')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($phoneSearch)->pluck('sale_number')->contains($cashSale->sale_number));

        $cashOnly = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?payment_method=cash&per_page=50')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($cashOnly)->pluck('sale_number')->contains($cashSale->sale_number));
        $this->assertFalse(collect($cashOnly)->pluck('sale_number')->contains($cardSale->sale_number));

        $managerHistory = $this->actingAs($manager)
            ->getJson('/pos/api/sales?per_page=50')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($managerHistory)->pluck('sale_number')->contains($cashSale->sale_number));
        $this->assertTrue(collect($managerHistory)->pluck('sale_number')->contains($cardSale->sale_number));

        $statusCompleted = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?status=completed&search=SAL-HISTORY-100')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($statusCompleted)->pluck('sale_number')->contains($cashSale->sale_number));
        $this->assertFalse(collect($statusCompleted)->pluck('sale_number')->contains($heldSale->sale_number));

        $cashierFiltered = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?cashier='.urlencode($cashier->name).'&search=SAL-HISTORY-100')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($cashierFiltered)->pluck('sale_number')->contains($cashSale->sale_number));

        $customerFiltered = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?customer=Aisha')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($customerFiltered)->pluck('sale_number')->contains($cashSale->sale_number));

        $dateFiltered = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?period=custom&date_from=2026-08-13&date_to=2026-08-13')
            ->assertOk()
            ->json('data');
        $this->assertCount(0, $dateFiltered);

        $paginated = $this->actingAs($cashier)
            ->getJson('/pos/api/sales?per_page=25')
            ->assertOk()
            ->json();
        $this->assertCount(25, $paginated['data']);
        $this->assertSame(25, $paginated['meta']['per_page']);
        $this->assertSame(29, $paginated['meta']['total']);
    }

    #[Test]
    public function sale_detail_returns_correct_items_payments_and_context_and_rejects_cross_company_access(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 50, 6);
        $manager = $this->userWithRole('manager', $warehouse);
        $customer = Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'name' => 'History Detail Customer',
            'phone' => '7000001',
        ]);

        $sale = $this->createCompletedSale($warehouse, $product, $manager, [
            'sale_number' => 'SAL-DETAIL-001',
            'customer_id' => $customer->id,
            'payment_method' => 'cash',
            'amount_tendered' => 25,
        ]);

        $response = $this->actingAs($manager)
            ->getJson("/pos/api/sales/{$sale->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('SAL-DETAIL-001', $response['sale_number']);
        $this->assertSame($customer->name, $response['customer']['name']);
        $this->assertSame($manager->name, $response['cashier']['name']);
        $this->assertSame($warehouse->name, $response['warehouse']['name']);
        $this->assertSame($product->sku, $response['items'][0]['sku']);
        $this->assertSame('cash', $response['payments'][0]['payment_method']);
        $this->assertSame('25.0000', $response['payments'][0]['amount_tendered']);

        $otherWarehouse = Warehouse::factory()->create();
        $otherCashier = $this->userWithRole('cashier', $otherWarehouse);

        $this->actingAs($otherCashier)
            ->getJson("/pos/api/sales/{$sale->id}")
            ->assertNotFound();
    }

    #[Test]
    public function pos_test_mode_allows_zero_stock_sales_only_while_enabled(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(sellingPrice: 10);
        $cashier = $this->userWithRole('cashier', $warehouse);
        $this->openShift($cashier);
        $warehouse->company->update(['pos_test_mode' => true]);

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertSee('"pos_test_mode":true', false);

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'test-mode-zero-stock-1',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [[
                    'payment_method' => 'cash',
                    'amount' => 10,
                    'amount_tendered' => 10,
                ]],
            ])
            ->assertOk();

        $this->assertSame('-1.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));

        $warehouse->company->update(['pos_test_mode' => false]);

        $this->actingAs($cashier)
            ->postJson('/pos/api/sales', [
                'client_transaction_uuid' => 'test-mode-zero-stock-2',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payments' => [[
                    'payment_method' => 'cash',
                    'amount' => 10,
                    'amount_tendered' => 10,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.stock.0', 'Insufficient stock for one or more items.');

        $this->assertSame('-1.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
    }

    private function userWithRole(string $role, Warehouse $warehouse): User
    {
        $user = User::factory()->forWarehouse($warehouse)->create();
        $user->assignRole(Role::findByName($role, 'web'));

        return $user;
    }

    private function openShift(User $user): void
    {
        $this->actingAs($user)
            ->postJson('/pos/api/shifts/open', ['opening_cash' => 0])
            ->assertOk();
    }

    /**
     * @return array{Warehouse, Product}
     */
    private function warehouseAndProduct(float $sellingPrice = 12.0): array
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create([
                'precision' => 0,
                'short_name' => 'pcs',
            ])->id,
            'selling_price' => $sellingPrice,
            'cost_price' => 6,
            'tax_rate' => 0,
            'track_inventory' => true,
            'allow_negative_stock' => false,
        ]);

        return [$warehouse, $product];
    }

    private function createCompletedSale(Warehouse $warehouse, Product $product, User $cashier, array $overrides = []): Sale
    {
        $quantity = $overrides['quantity'] ?? 1;
        $paymentMethod = $overrides['payment_method'] ?? 'cash';
        $lineSubtotal = (float) $product->selling_price * $quantity;
        $taxAmount = $lineSubtotal * ($product->effectiveTaxRate() / 100);
        $amount = $overrides['amount'] ?? round($lineSubtotal + $taxAmount, 4);

        return app(SalesService::class)->createSale(
            $warehouse->company_id,
            $warehouse->branch_id,
            $warehouse->id,
            [[
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]],
            [[
                'payment_method' => $paymentMethod,
                'amount' => $amount,
                'amount_tendered' => $overrides['amount_tendered'] ?? $amount,
                'reference' => $overrides['reference'] ?? null,
            ]],
            [
                'sale_number' => $overrides['sale_number'] ?? strtoupper(fake()->unique()->bothify('SAL-HIST-####')),
                'sale_date' => $overrides['sale_date'] ?? '2026-08-14',
                'completed_at' => isset($overrides['completed_at']) ? Carbon::parse($overrides['completed_at']) : Carbon::parse('2026-08-14 10:00:00'),
                'customer_id' => $overrides['customer_id'] ?? null,
                'created_by' => $cashier->id,
                'client_transaction_uuid' => $overrides['client_transaction_uuid'] ?? strtolower(fake()->unique()->bothify('pos-history-####')),
            ],
        );
    }
}
