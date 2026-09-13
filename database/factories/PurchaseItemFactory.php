<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function configure(): static
    {
        return $this->afterMaking(function (PurchaseItem $item): void {
            if (! $item->purchase_id) {
                $purchase = Purchase::factory()->create();
                $item->purchase_id = $purchase->id;
                $item->company_id = $purchase->company_id;
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
            'purchase_id' => null,
            'company_id' => null,
            'product_id' => null,
            'description' => fake()->words(3, true),
            'ordered_quantity' => 5,
            'received_quantity' => 0,
            'unit_cost' => 10,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'tax_category' => 'standard_rated',
            'price_includes_tax' => false,
            'input_tax_claimable' => true,
            'line_total' => 50,
        ];
    }
}
