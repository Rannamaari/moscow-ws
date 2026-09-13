<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function configure(): static
    {
        return $this->afterMaking(function (Product $product): void {
            if (! $product->company_id) {
                $product->company_id = Company::factory()->create()->id;
            }

            if (! $product->unit_id) {
                $product->unit_id = Unit::factory()->create()->id;
            }

            if ($product->category_id) {
                $product->company_id = Category::query()->findOrFail($product->category_id)->company_id;
            }

            if ($product->brand_id) {
                $product->company_id = Brand::query()->findOrFail($product->brand_id)->company_id;
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
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => null,
            'sku' => strtoupper(Str::random(10)),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'cost_price' => fake()->randomFloat(4, 0, 100),
            'selling_price' => fake()->randomFloat(4, 1, 200),
            'wholesale_price' => fake()->optional()->randomFloat(4, 1, 180),
            'tax_rate' => fake()->randomElement([0, 8, 10]),
            'is_taxable' => true,
            'tax_category' => 'standard_rated',
            'minimum_stock' => fake()->randomFloat(4, 0, 100),
            'allow_negative_stock' => false,
            'track_inventory' => true,
            'is_active' => true,
        ];
    }

    public function forCompany(?Company $company = null): static
    {
        $company ??= Company::factory()->create();

        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }

    public function withCategory(?Category $category = null): static
    {
        $category ??= Category::factory()->create();

        return $this->state(fn () => [
            'company_id' => $category->company_id,
            'category_id' => $category->id,
        ]);
    }

    public function withBrand(?Brand $brand = null): static
    {
        $brand ??= Brand::factory()->create();

        return $this->state(fn () => [
            'company_id' => $brand->company_id,
            'brand_id' => $brand->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
