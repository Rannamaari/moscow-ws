<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

class BenchmarkProductsCommand extends Command
{
    protected $signature = 'moscow-traders-wholesale:benchmark-products {--company= : Company UUID to benchmark}';

    protected $description = 'Benchmark core product catalog lookup queries.';

    public function handle(): int
    {
        $company = $this->option('company')
            ? Company::query()->find($this->option('company'))
            : Company::query()->where('name', 'Moscow Traders Wholesale')->first();

        if (! $company) {
            $this->error('Benchmark company not found.');

            return self::FAILURE;
        }

        $product = Product::query()
            ->with('primaryBarcode')
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();

        if (! $product || ! $product->primaryBarcode) {
            $this->error('No benchmarkable product with a primary barcode was found for the company.');

            return self::FAILURE;
        }

        $nameFragment = substr($product->name, 0, min(5, strlen($product->name)));

        $count = Product::query()->where('company_id', $company->id)->count();

        $barcodeRaw = $this->profileQuery(function () use ($company, $product): ?stdClass {
            return DB::table('product_barcodes')
                ->join('products', 'products.id', '=', 'product_barcodes.product_id')
                ->where('product_barcodes.company_id', $company->id)
                ->where('product_barcodes.barcode', $product->primaryBarcode->barcode)
                ->where('products.company_id', $company->id)
                ->where('products.is_active', true)
                ->select('products.id')
                ->first();
        });

        $barcodeApp = $this->profileQuery(function () use ($company, $product): ?Product {
            return Product::findByBarcode($company->id, $product->primaryBarcode->barcode);
        });

        $skuRaw = $this->profileQuery(function () use ($company, $product): ?stdClass {
            return DB::table('products')
                ->where('company_id', $company->id)
                ->where('sku', $product->sku)
                ->where('is_active', true)
                ->select('id')
                ->first();
        });

        $skuApp = $this->profileQuery(function () use ($company, $product): ?Product {
            return Product::findBySku($company->id, $product->sku);
        });

        $nameSearch = $this->profileQuery(function () use ($company, $nameFragment) {
            return Product::searchByName($company->id, $nameFragment)->limit(25)->get();
        });

        $pagination = $this->profileQuery(function () use ($company) {
            Product::query()
                ->with(['category', 'brand', 'unit', 'primaryBarcode'])
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->paginate(50)
                ->items();
        });

        $this->table(
            ['Metric', 'Time', 'Queries'],
            [
                ['Company', $company->name, '-'],
                ['Product count', (string) $count, '-'],
                ['Exact barcode raw lookup', sprintf('%.3f ms', $barcodeRaw['time_ms']), (string) $barcodeRaw['query_count']],
                ['Exact barcode app lookup', sprintf('%.3f ms', $barcodeApp['time_ms']), (string) $barcodeApp['query_count']],
                ['Exact SKU raw lookup', sprintf('%.3f ms', $skuRaw['time_ms']), (string) $skuRaw['query_count']],
                ['Exact SKU app lookup', sprintf('%.3f ms', $skuApp['time_ms']), (string) $skuApp['query_count']],
                ['Partial name search', sprintf('%.3f ms', $nameSearch['time_ms']), (string) $nameSearch['query_count']],
                ['Paginated list query', sprintf('%.3f ms', $pagination['time_ms']), (string) $pagination['query_count']],
            ]
        );

        $this->line('Barcode raw SQL: '.$barcodeRaw['queries'][0]['query']);
        $this->line('Barcode app first SQL: '.$barcodeApp['queries'][0]['query']);
        $this->line('SKU raw SQL: '.$skuRaw['queries'][0]['query']);
        $this->line('SKU app first SQL: '.$skuApp['queries'][0]['query']);

        return self::SUCCESS;
    }

    /**
     * @return array{time_ms:float, query_count:int, queries:array<int, array<string, mixed>>, result:mixed}
     */
    private function profileQuery(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);
        $result = $callback();
        $timeMs = (hrtime(true) - $start) / 1_000_000;
        $queries = DB::getQueryLog();

        return [
            'time_ms' => $timeMs,
            'query_count' => count($queries),
            'queries' => $queries,
            'result' => $result,
        ];
    }
}
