<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Sale $sale): void {
            if (! $sale->warehouse_id) {
                $warehouse = Warehouse::factory()->create();
                $sale->warehouse_id = $warehouse->id;
                $sale->company_id = $warehouse->company_id;
                $sale->branch_id = $warehouse->branch_id;
            }

            if (! $sale->branch_id && $sale->company_id) {
                $sale->branch_id = Branch::query()->where('company_id', $sale->company_id)->value('id');
            }

            if ($sale->customer_id) {
                $sale->company_id = Customer::query()->findOrFail($sale->customer_id)->company_id;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => null,
            'branch_id' => null,
            'warehouse_id' => null,
            'customer_id' => null,
            'customer_tax_number' => null,
            'payment_terms_days' => null,
            'sale_number' => strtoupper(fake()->unique()->bothify('SAL-######')),
            'status' => SaleStatus::Completed,
            'client_transaction_uuid' => null,
            'sale_date' => now()->toDateString(),
            'due_date' => null,
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'paid_total' => 100,
            'balance_due' => 0,
            'notes' => fake()->optional()->sentence(),
            'cancellation_reason' => null,
            'cancellation_notes' => null,
            'created_by' => null,
            'cancelled_by' => null,
            'completed_at' => now(),
            'voided_at' => null,
            'cancelled_at' => null,
        ];
    }
}
