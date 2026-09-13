<?php

namespace Tests\Feature;

use App\Enums\CustomerTransactionType;
use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\SupplierTransactionType;
use App\Exceptions\InventoryException;
use App\Exceptions\TransactionException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductBranchPrice;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\CustomerLedgerService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SalesService;
use App\Services\SupplierLedgerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchasingSalesEngineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function supplier_code_is_unique_within_company_but_allowed_in_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $supplier = Supplier::factory()->create([
            'company_id' => $company->id,
            'code' => 'SUP-100',
        ]);

        $this->assertInstanceOf(Company::class, $supplier->company);

        Supplier::factory()->create([
            'company_id' => $otherCompany->id,
            'code' => 'SUP-100',
        ]);

        try {
            Supplier::factory()->create([
                'company_id' => $company->id,
                'code' => 'SUP-100',
            ]);
            $this->fail('Expected duplicate supplier code to fail in the same company.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function supplier_payable_ledger_and_payment_behave_correctly(): void
    {
        $supplier = Supplier::factory()->create([
            'opening_balance' => 500,
        ]);

        app(SupplierLedgerService::class)->recordTransaction(
            $supplier->company_id,
            $supplier->id,
            SupplierTransactionType::Purchase,
            2000
        );

        app(SupplierLedgerService::class)->recordTransaction(
            $supplier->company_id,
            $supplier->id,
            SupplierTransactionType::Payment,
            -1000
        );

        $this->assertSame('1500.0000', app(SupplierLedgerService::class)->currentPayable($supplier->id));
    }

    #[Test]
    public function purchase_totals_and_historical_unit_cost_are_calculated_server_side(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 7.5, sellingPrice: 12);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);

        $purchase = app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $supplier->id, [
            [
                'product_id' => $product->id,
                'ordered_quantity' => 5,
                'unit_cost' => 8,
                'discount_amount' => 2,
                'tax_rate' => 10,
            ],
        ], [
            'branch_id' => $warehouse->branch_id,
            'shipping_total' => 3,
            'other_cost_total' => 1,
        ]);

        $item = $purchase->items->firstOrFail();

        $this->assertSame('40.0000', $purchase->subtotal);
        $this->assertSame('2.0000', $purchase->discount_total);
        $this->assertSame('3.8000', $purchase->tax_total);
        $this->assertSame('45.8000', $purchase->grand_total);
        $this->assertSame('8.0000', $item->unit_cost);
    }

    #[Test]
    public function purchase_receipt_is_transactional_and_updates_inventory_and_movements(): void
    {
        [$warehouse, $trackedProduct] = $this->warehouseAndProduct();
        $nonInventoryProduct = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'track_inventory' => false,
        ]);
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);

        $purchase = app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $supplier->id, [
            [
                'product_id' => $trackedProduct->id,
                'ordered_quantity' => 5,
                'unit_cost' => 8,
            ],
            [
                'product_id' => $nonInventoryProduct->id,
                'ordered_quantity' => 2,
                'unit_cost' => 5,
            ],
        ], [
            'branch_id' => $warehouse->branch_id,
        ]);

        $this->expectException(InventoryException::class);

        app(PurchaseService::class)->receivePurchase($purchase->id, [
            $purchase->items[0]->id => 5,
            $purchase->items[1]->id => 2,
        ]);

        $purchase->refresh();

        $this->assertSame('0.0000', $purchase->items()->findOrFail($purchase->items[0]->id)->received_quantity);
        $this->assertSame('0.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $trackedProduct->id));
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'type' => StockMovementType::Purchase->value,
        ]);
    }

    #[Test]
    public function partial_and_final_purchase_receipts_update_status_and_quantities_without_double_receiving(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $purchase = app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $supplier->id, [
            ['product_id' => $product->id, 'ordered_quantity' => 10, 'unit_cost' => 8.25],
        ], ['branch_id' => $warehouse->branch_id]);

        $purchase = app(PurchaseService::class)->receivePurchase($purchase->id, [
            $purchase->items->firstOrFail()->id => 6,
        ]);

        $this->assertSame(PurchaseStatus::PartiallyReceived, $purchase->status);
        $this->assertSame('6.0000', $purchase->items->firstOrFail()->received_quantity);
        $this->assertSame('6.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));

        $purchase = app(PurchaseService::class)->receivePurchase($purchase->id, [
            $purchase->items->firstOrFail()->id => 4,
        ]);

        $this->assertSame(PurchaseStatus::Received, $purchase->status);
        $this->assertSame('10.0000', $purchase->items->firstOrFail()->received_quantity);

        try {
            app(PurchaseService::class)->receivePurchase($purchase->id, [
                $purchase->items->firstOrFail()->id => 1,
            ]);
            $this->fail('Expected over-receiving to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function purchase_receipts_update_product_and_branch_costs_using_weighted_average(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 8);
        $product->forceFill(['tax_category' => 'exempt'])->save();
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        app(InventoryService::class)->setOpeningStock(
            $warehouse->company_id,
            $warehouse->id,
            $product->id,
            10,
            8,
        );

        foreach ([[10, 12], [5, 20]] as [$quantity, $unitCost]) {
            $purchase = app(PurchaseService::class)->createPurchase(
                $warehouse->company_id,
                $warehouse->id,
                $supplier->id,
                [[
                    'product_id' => $product->id,
                    'ordered_quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'tax_category' => 'exempt',
                ]],
                ['branch_id' => $warehouse->branch_id],
            );
            app(PurchaseService::class)->receivePurchase($purchase->id, [
                $purchase->items->firstOrFail()->id => $quantity,
            ]);
        }

        $balance = InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $branchPrice = ProductBranchPrice::query()
            ->where('branch_id', $warehouse->branch_id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame('25.0000', $balance->quantity);
        $this->assertSame('12.0000', $balance->average_cost);
        $this->assertSame('12.0000', $product->fresh()->cost_price);
        $this->assertSame('12.0000', $branchPrice->cost_price);
    }

    #[Test]
    public function cancelled_purchase_cannot_be_received(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $purchase = app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $supplier->id, [
            ['product_id' => $product->id, 'ordered_quantity' => 10, 'unit_cost' => 8],
        ], [
            'branch_id' => $warehouse->branch_id,
            'status' => PurchaseStatus::Cancelled,
        ]);

        $this->expectException(TransactionException::class);
        app(PurchaseService::class)->receivePurchase($purchase->id, [
            $purchase->items->firstOrFail()->id => 1,
        ]);
    }

    #[Test]
    public function purchase_payment_updates_totals_and_rejects_overpayment(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $purchase = app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $supplier->id, [
            ['product_id' => $product->id, 'ordered_quantity' => 10, 'unit_cost' => 8],
        ], ['branch_id' => $warehouse->branch_id]);

        app(PurchaseService::class)->recordPayment($purchase->id, 30, 'cash');
        $purchase->refresh();

        $this->assertSame('30.0000', $purchase->paid_total);
        $this->assertSame('50.0000', $purchase->balance_due);
        $this->assertSame('50.0000', app(SupplierLedgerService::class)->currentPayable($supplier->id));

        try {
            app(PurchaseService::class)->recordPayment($purchase->id, 51, 'cash');
            $this->fail('Expected overpayment to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function purchase_return_reduces_stock_creates_movement_and_limits_returnable_quantity(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();
        $supplier = Supplier::factory()->create(['company_id' => $warehouse->company_id]);
        $purchase = app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $supplier->id, [
            ['product_id' => $product->id, 'ordered_quantity' => 10, 'unit_cost' => 8],
        ], ['branch_id' => $warehouse->branch_id]);
        $purchase = app(PurchaseService::class)->receivePurchase($purchase->id, [
            $purchase->items->firstOrFail()->id => 10,
        ]);

        $purchaseReturn = app(PurchaseService::class)->returnPurchase($purchase->id, [
            $purchase->items->firstOrFail()->id => 3,
        ]);

        $this->assertSame('7.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $purchaseReturn->id,
            'type' => StockMovementType::PurchaseReturn->value,
        ]);
        $this->assertSame('56.0000', app(SupplierLedgerService::class)->currentPayable($supplier->id));

        try {
            app(PurchaseService::class)->returnPurchase($purchase->id, [
                $purchase->items->firstOrFail()->id => 8,
            ]);
            $this->fail('Expected over-return to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function customer_code_is_unique_per_company_and_walk_in_customer_is_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::query()->where('name', 'Moscow Traders Wholesale')->firstOrFail();
        $this->assertDatabaseHas('customers', [
            'company_id' => $company->id,
            'code' => 'WALK-IN',
            'name' => 'Walk-in Customer',
        ]);

        Customer::factory()->create([
            'company_id' => $company->id,
            'code' => 'CUS-100',
        ]);

        $otherCompany = Company::factory()->create();
        Customer::factory()->create([
            'company_id' => $otherCompany->id,
            'code' => 'CUS-100',
        ]);

        try {
            Customer::factory()->create([
                'company_id' => $company->id,
                'code' => 'CUS-100',
            ]);
            $this->fail('Expected duplicate customer code to fail in the same company.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function customer_receivable_ledger_and_payments_behave_correctly(): void
    {
        $customer = Customer::factory()->create([
            'opening_balance' => 100,
        ]);

        app(CustomerLedgerService::class)->recordTransaction(
            $customer->company_id,
            $customer->id,
            CustomerTransactionType::Sale,
            400
        );

        $this->assertSame('500.0000', app(CustomerLedgerService::class)->currentBalance($customer->id));

        app(CustomerLedgerService::class)->recordPayment($customer->company_id, $customer->id, 150, 'cash');

        $this->assertSame('350.0000', app(CustomerLedgerService::class)->currentBalance($customer->id));
    }

    #[Test]
    public function sale_completion_updates_inventory_movements_and_preserves_historical_price_and_cost(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 6, sellingPrice: 14);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);

        $sale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 15,
            ],
        ], [
            ['payment_method' => 'cash', 'amount' => 30, 'amount_tendered' => 30],
        ]);

        $item = $sale->items->firstOrFail();

        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame('15.0000', $item->unit_price);
        $this->assertSame('6.0000', $item->unit_cost);
        $this->assertSame('8.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'type' => StockMovementType::Sale->value,
        ]);
    }

    #[Test]
    public function sale_respects_negative_stock_rules_and_non_inventory_products_do_not_create_movements(): void
    {
        [$warehouse, $stockProduct] = $this->warehouseAndProduct(allowNegativeStock: false);
        $nonInventoryProduct = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'track_inventory' => false,
            'tax_rate' => 0,
            'selling_price' => 25,
        ]);

        $this->expectException(InventoryException::class);

        app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $stockProduct->id, 'quantity' => 1],
        ], [
            ['payment_method' => 'cash', 'amount' => 15],
        ]);

        $sale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $nonInventoryProduct->id, 'quantity' => 2],
        ], [
            ['payment_method' => 'cash', 'amount' => 50],
        ]);

        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'product_id' => $nonInventoryProduct->id,
        ]);
    }

    #[Test]
    public function fully_paid_and_partially_paid_sales_behave_correctly_with_credit_limits(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 5, sellingPrice: 20);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 20, 5);
        $customer = Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'credit_limit' => 100,
        ]);

        $fullyPaidSale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $product->id, 'quantity' => 2],
        ], [
            ['payment_method' => 'cash', 'amount' => 20],
            ['payment_method' => 'card', 'amount' => 20],
        ], [
            'customer_id' => $customer->id,
        ]);

        $this->assertSame('0.0000', $fullyPaidSale->balance_due);
        $this->assertCount(2, $fullyPaidSale->payments);

        $creditSale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $product->id, 'quantity' => 3],
        ], [
            ['payment_method' => 'cash', 'amount' => 20],
        ], [
            'customer_id' => $customer->id,
        ]);

        $this->assertSame('40.0000', $creditSale->balance_due);
        $this->assertSame('40.0000', app(CustomerLedgerService::class)->currentBalance($customer->id));

        try {
            app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
                ['product_id' => $product->id, 'quantity' => 4],
            ], [], [
                'customer_id' => $customer->id,
            ]);
            $this->fail('Expected credit limit to be enforced.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function walk_in_customer_cannot_receive_credit_and_held_sales_do_not_affect_inventory_or_receivables(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 5, sellingPrice: 20);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 5);
        $walkIn = Customer::factory()->walkIn()->create([
            'company_id' => $warehouse->company_id,
        ]);

        $heldSale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $product->id, 'quantity' => 2],
        ], [], [
            'customer_id' => $walkIn->id,
            'status' => SaleStatus::Held,
        ]);

        $this->assertSame('10.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
        $this->assertSame('0.0000', app(CustomerLedgerService::class)->currentBalance($walkIn->id));

        $completed = app(SalesService::class)->completeSale($heldSale->id, [
            ['payment_method' => 'cash', 'amount' => 40],
        ]);

        $this->assertSame(SaleStatus::Completed, $completed->status);
        $this->assertSame('8.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));

        try {
            app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
                ['product_id' => $product->id, 'quantity' => 1],
            ], [], [
                'customer_id' => $walkIn->id,
            ]);
            $this->fail('Expected walk-in credit sale to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function sale_completion_is_transactional(): void
    {
        [$warehouse, $trackedProduct] = $this->warehouseAndProduct(costPrice: 5, sellingPrice: 20);
        $nonInventory = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'track_inventory' => false,
            'allow_negative_stock' => false,
            'tax_rate' => 0,
            'selling_price' => 10,
        ]);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $trackedProduct->id, 5, 5);

        $heldSale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $trackedProduct->id, 'quantity' => 2],
            ['product_id' => $nonInventory->id, 'quantity' => 1],
        ], [], [
            'status' => SaleStatus::Held,
        ]);

        $completed = app(SalesService::class)->completeSale($heldSale->id, [
            ['payment_method' => 'cash', 'amount' => 50],
        ]);

        $this->assertSame('3.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $trackedProduct->id));
        $this->assertSame(SaleStatus::Completed, $completed->status);
    }

    #[Test]
    public function sale_returns_restore_inventory_limit_quantities_and_update_receivables(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 6, sellingPrice: 20);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 6);
        $customer = Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'credit_limit' => 1000,
        ]);

        $sale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [
            ['payment_method' => 'cash', 'amount' => 40],
        ], [
            'customer_id' => $customer->id,
        ]);

        $this->assertSame('60.0000', app(CustomerLedgerService::class)->currentBalance($customer->id));

        $saleReturn = app(SalesService::class)->returnSale($sale->id, [
            $sale->items->firstOrFail()->id => 2,
        ]);

        $sale->refresh();

        $this->assertSame('7.0000', app(InventoryService::class)->getBalance($warehouse->company_id, $warehouse->id, $product->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => SaleReturn::class,
            'reference_id' => $saleReturn->id,
            'type' => StockMovementType::SaleReturn->value,
        ]);
        $this->assertSame('20.0000', $sale->balance_due);
        $this->assertSame('20.0000', app(CustomerLedgerService::class)->currentBalance($customer->id));

        try {
            app(SalesService::class)->returnSale($sale->id, [
                $sale->items->firstOrFail()->id => 4,
            ]);
            $this->fail('Expected over-return to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function sale_idempotency_and_gross_profit_use_historical_costs(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct(costPrice: 12, sellingPrice: 20);
        app(InventoryService::class)->setOpeningStock($warehouse->company_id, $warehouse->id, $product->id, 10, 12);

        $sale = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [
            ['payment_method' => 'cash', 'amount' => 100],
        ], [
            'client_transaction_uuid' => 'sale-idem-001',
        ]);

        $duplicate = app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [
            ['payment_method' => 'cash', 'amount' => 100],
        ], [
            'client_transaction_uuid' => 'sale-idem-001',
        ]);

        $this->assertTrue($sale->is($duplicate));
        $this->assertSame('40.0000', app(SalesService::class)->grossProfitForSale($sale->id));
    }

    #[Test]
    public function cross_company_supplier_customer_and_sale_combinations_are_rejected(): void
    {
        [$warehouse, $product] = $this->warehouseAndProduct();
        $otherCompany = Company::factory()->create();
        $otherSupplier = Supplier::factory()->create(['company_id' => $otherCompany->id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $otherCompany->id]);

        try {
            app(PurchaseService::class)->createPurchase($warehouse->company_id, $warehouse->id, $otherSupplier->id, [
                ['product_id' => $product->id, 'ordered_quantity' => 1, 'unit_cost' => 8],
            ], ['branch_id' => $warehouse->branch_id]);
            $this->fail('Expected cross-company supplier use to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }

        try {
            app(SalesService::class)->createSale($warehouse->company_id, $warehouse->branch_id, $warehouse->id, [
                ['product_id' => $product->id, 'quantity' => 1],
            ], [
                ['payment_method' => 'cash', 'amount' => 10],
            ], [
                'customer_id' => $otherCustomer->id,
            ]);
            $this->fail('Expected cross-company customer use to be rejected.');
        } catch (TransactionException) {
            $this->assertTrue(true);
        }
    }

    /**
     * @return array{Warehouse, Product}
     */
    private function warehouseAndProduct(
        float $costPrice = 10.0,
        float $sellingPrice = 15.0,
        bool $allowNegativeStock = false,
    ): array {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create([
            'company_id' => $warehouse->company_id,
            'unit_id' => Unit::factory()->create()->id,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'tax_rate' => 0,
            'allow_negative_stock' => $allowNegativeStock,
            'track_inventory' => true,
        ]);

        return [$warehouse, $product];
    }
}
