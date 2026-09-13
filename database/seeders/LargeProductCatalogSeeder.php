<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LargeProductCatalogSeeder extends Seeder
{
    private const PRODUCT_COUNT = 20000;

    private const CHUNK_SIZE = 1000;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = Company::query()->where('name', 'Moscow Traders Wholesale')->firstOrFail();

        $categories = Category::query()->where('company_id', $company->id)->pluck('id')->values();
        $brands = Brand::query()->where('company_id', $company->id)->pluck('id')->values();
        $units = Unit::query()->pluck('id')->values();

        if ($categories->isEmpty() || $brands->isEmpty() || $units->isEmpty()) {
            throw new \RuntimeException('Seed units, categories, and brands before running the large catalog seeder.');
        }

        $perfProductIds = Product::query()
            ->where('company_id', $company->id)
            ->where('sku', 'like', 'PERF-%')
            ->pluck('id');

        if ($perfProductIds->isNotEmpty()) {
            ProductBarcode::query()->whereIn('product_id', $perfProductIds)->delete();
            Product::query()->whereIn('id', $perfProductIds)->delete();
        }

        foreach (range(0, (int) ceil(self::PRODUCT_COUNT / self::CHUNK_SIZE) - 1) as $chunkIndex) {
            $products = [];
            $barcodes = [];

            foreach (range(1, self::CHUNK_SIZE) as $offset) {
                $sequence = ($chunkIndex * self::CHUNK_SIZE) + $offset;

                if ($sequence > self::PRODUCT_COUNT) {
                    break;
                }

                $productId = (string) Str::uuid();
                $now = now();

                $products[] = [
                    'id' => $productId,
                    'company_id' => $company->id,
                    'category_id' => $categories[($sequence - 1) % $categories->count()],
                    'brand_id' => $brands[($sequence - 1) % $brands->count()],
                    'unit_id' => $units[($sequence - 1) % $units->count()],
                    'sku' => sprintf('PERF-%05d', $sequence),
                    'name' => sprintf('Performance Product %05d', $sequence),
                    'description' => null,
                    'cost_price' => number_format(5 + (($sequence % 100) / 10), 4, '.', ''),
                    'selling_price' => number_format(10 + (($sequence % 200) / 10), 4, '.', ''),
                    'wholesale_price' => number_format(9 + (($sequence % 150) / 10), 4, '.', ''),
                    'tax_rate' => 0,
                    'is_taxable' => true,
                    'minimum_stock' => number_format(($sequence % 50), 4, '.', ''),
                    'allow_negative_stock' => false,
                    'track_inventory' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $barcodes[] = [
                    'id' => (string) Str::uuid(),
                    'product_id' => $productId,
                    'company_id' => $company->id,
                    'barcode' => sprintf('PERFBC%07d', $sequence),
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($products, $barcodes): void {
                Product::query()->insert($products);
                ProductBarcode::query()->insert($barcodes);
            });
        }
    }
}
