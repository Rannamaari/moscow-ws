<?php

namespace Tests\Feature;

use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Product;
use App\Services\SalesService;
use App\Services\StorefrontCatalog;
use App\Services\StorefrontContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TaxConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_tax_rate_only_applies_to_products_marked_as_taxable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::query()->where('name', 'Moscow Traders Wholesale')->firstOrFail();
        $company->update(['default_tax_rate' => 8]);

        $products = Product::query()->where('company_id', $company->id)->limit(2)->get();
        $taxable = $products->first();
        $exempt = $products->last();

        $taxable->update(['selling_price' => 100, 'is_taxable' => true, 'track_inventory' => false]);
        $exempt->update(['selling_price' => 100, 'is_taxable' => false, 'track_inventory' => false]);

        $context = Mockery::mock(StorefrontContext::class);
        $context->shouldReceive('company')->andReturn($company);
        $catalog = new StorefrontCatalog($context);

        $this->assertSame(108.0, $catalog->price($taxable->fresh()));
        $this->assertSame(100.0, $catalog->price($exempt->fresh()));

        $branch = $company->branches()->firstOrFail();
        $warehouse = $company->warehouses()->firstOrFail();
        $sale = app(SalesService::class)->createSale(
            $company->id,
            $branch->id,
            $warehouse->id,
            [
                ['product_id' => $taxable->id, 'quantity' => 1],
                ['product_id' => $exempt->id, 'quantity' => 1],
            ],
            [],
            ['status' => SaleStatus::Held],
        );

        $this->assertSame('8.0000', $sale->items->firstWhere('product_id', $taxable->id)->tax_rate);
        $this->assertSame('0.0000', $sale->items->firstWhere('product_id', $exempt->id)->tax_rate);
        $this->assertSame('8.0000', $sale->tax_total);
        $this->assertSame('208.0000', $sale->grand_total);
    }
}
