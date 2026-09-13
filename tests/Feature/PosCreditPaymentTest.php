<?php

namespace Tests\Feature;

use App\Enums\CustomerTransactionType;
use App\Enums\SaleStatus;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CustomerLedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosCreditPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function an_authorized_pos_user_can_receive_a_credit_payment_and_view_the_updated_statement(): void
    {
        [$warehouse, $user, $customer, $sale] = $this->creditSaleContext();
        $shiftId = $this->actingAs($user)
            ->postJson('/pos/api/shifts/open', ['opening_cash' => 20])
            ->assertOk()
            ->json('data.id');

        $this->postJson("/pos/api/sales/{$sale->id}/payments", [
            'amount' => 40,
            'payment_method' => 'cash',
            'reference' => 'RCPT-1001',
        ])->assertOk()
            ->assertJsonPath('message', 'Customer payment received.')
            ->assertJsonPath('data.paid_total', '40.0000')
            ->assertJsonPath('data.balance_due', '60.0000');

        $this->assertDatabaseHas('customer_payments', [
            'sale_id' => $sale->id,
            'cashier_shift_id' => $shiftId,
            'amount' => 40,
            'reference' => 'RCPT-1001',
        ]);
        $this->assertSame('60.0000', app(CustomerLedgerService::class)->currentBalance($customer->id, $warehouse->branch->currency));

        $this->getJson("/pos/api/customers/{$customer->id}/statement")
            ->assertOk()
            ->assertJsonPath('data.summary.total_invoiced', '100.0000')
            ->assertJsonPath('data.summary.total_paid', '40.0000')
            ->assertJsonPath('data.summary.outstanding_balance', '60.0000')
            ->assertJsonPath('data.invoices.0.sale_number', $sale->sale_number)
            ->assertJsonPath('data.invoices.0.balance_due', '60.0000')
            ->assertJsonPath('data.payments.0.reference', 'RCPT-1001');

        $this->postJson("/pos/api/shifts/{$shiftId}/close", ['closing_cash' => 60])
            ->assertOk();
        $shift = CashierShift::query()->findOrFail($shiftId);

        $this->assertSame('60.0000', $shift->expected_cash);
        $this->assertSame('40.0000', $shift->report_snapshot['cash_received']);
        $this->assertSame(1, $shift->report_snapshot['credit_collections_count']);
        $this->assertSame('40.0000', $shift->report_snapshot['credit_collections_total']);
    }

    #[Test]
    public function a_pos_credit_payment_cannot_exceed_the_invoice_balance(): void
    {
        [, $user, , $sale] = $this->creditSaleContext();
        $this->actingAs($user)->postJson('/pos/api/shifts/open', ['opening_cash' => 0])->assertOk();

        $this->postJson("/pos/api/sales/{$sale->id}/payments", [
            'amount' => 100.01,
            'payment_method' => 'cash',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.sale.0', 'Customer payment cannot exceed the outstanding balance on this sale.');

        $this->assertSame('0.0000', $sale->fresh()->paid_total);
        $this->assertSame('100.0000', $sale->fresh()->balance_due);
        $this->assertDatabaseCount('customer_payments', 0);
    }

    #[Test]
    public function displayed_two_decimal_balance_can_settle_a_four_decimal_invoice(): void
    {
        [$warehouse, $user, $customer, $sale] = $this->creditSaleContext();
        $sale->update([
            'subtotal' => 3904.9964,
            'grand_total' => 3904.9964,
            'paid_total' => 0,
            'balance_due' => 3904.9964,
        ]);
        CustomerTransaction::query()
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->update(['amount' => 3904.9964]);
        $this->actingAs($user)->postJson('/pos/api/shifts/open', ['opening_cash' => 0])->assertOk();

        $this->postJson("/pos/api/sales/{$sale->id}/payments", [
            'amount' => 3905.00,
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.paid_total', '3904.9964')
            ->assertJsonPath('data.balance_due', '0.0000');

        $this->assertDatabaseHas('customer_payments', [
            'sale_id' => $sale->id,
            'amount' => 3904.9964,
        ]);
        $this->assertSame(
            '0.0000',
            app(CustomerLedgerService::class)->currentBalance($customer->id, $warehouse->branch->currency),
        );
    }

    /** @return array{Warehouse, User, Customer, Sale} */
    private function creditSaleContext(): array
    {
        $warehouse = Warehouse::factory()->create();
        $user = User::factory()->forWarehouse($warehouse)->create();
        $user->assignRole(Role::findByName('admin'));
        $customer = Customer::factory()->create([
            'company_id' => $warehouse->company_id,
            'credit_limit' => 1000,
        ]);
        $sale = Sale::factory()->create([
            'company_id' => $warehouse->company_id,
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'status' => SaleStatus::Completed,
            'currency' => $warehouse->branch->currency,
            'subtotal' => 100,
            'grand_total' => 100,
            'paid_total' => 0,
            'balance_due' => 100,
            'completed_at' => now(),
        ]);
        app(CustomerLedgerService::class)->recordTransaction(
            $warehouse->company_id,
            $customer->id,
            CustomerTransactionType::Sale,
            100,
            [
                'currency' => $warehouse->branch->currency,
                'reference_type' => Sale::class,
                'reference_id' => $sale->id,
                'reference_number' => $sale->sale_number,
            ],
        );

        return [$warehouse, $user, $customer, $sale];
    }
}
