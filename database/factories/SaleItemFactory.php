<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function configure(): static
    {
        return $this->afterMaking(function (SaleItem $item): void {
            if (! $item->sale_id) {
                $sale = Sale::factory()->create();
                $item->sale_id = $sale->id;
                $item->company_id = $sale->company_id;
            }

            if (! $item->product_id) {
                $product = Product::factory()->create([
                    'company_id' => $item->company_id,
                ]);
                $item->product_id = $product->id;
                $item->description = $item->description ?: $product->name;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => null,
            'company_id' => null,
            'product_id' => null,
            'description' => fake()->words(3, true),
            'quantity' => 2,
            'unit_price' => 20,
            'unit_cost' => 10,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'tax_category' => 'standard_rated',
            'line_total' => 40,
        ];
    }
}
