<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use SplFileObject;

class ProductImportService
{
    /**
     * @return array{created:int, errors:list<string>}
     */
    public function import(string $companyId, string $path): array
    {
        $company = Company::query()->find($companyId);

        if (! $company) {
            throw new InvalidArgumentException('Company not found.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('CSV file is not readable.');
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $headers = $file->fgetcsv();

        if (! is_array($headers) || $headers === [null] || $headers === false) {
            throw new InvalidArgumentException('CSV file is missing a header row.');
        }

        $headers = array_map(static fn ($header) => trim((string) $header), $headers);

        $requiredHeaders = ['sku', 'name', 'barcode', 'unit', 'cost_price', 'selling_price'];

        foreach ($requiredHeaders as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                throw new InvalidArgumentException("Missing required CSV column: {$requiredHeader}");
            }
        }

        $created = 0;
        $errors = [];
        $seenSkus = [];
        $seenBarcodes = [];

        $rowNumber = 1;

        while (! $file->eof()) {
            $row = $file->fgetcsv();

            if (! is_array($row) || $row === [null]) {
                $rowNumber++;

                continue;
            }

            $row = array_pad($row, count($headers), null);

            if (collect($row)->every(static fn ($value) => $value === null || trim((string) $value) === '')) {
                $rowNumber++;

                continue;
            }

            $data = array_combine($headers, $row);

            if ($data === false) {
                $errors[] = "Row {$rowNumber}: malformed CSV row.";
                $rowNumber++;

                continue;
            }

            $data = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $data);

            $validator = Validator::make($data, [
                'sku' => [
                    'required',
                    'string',
                    Rule::unique('products', 'sku')->where(fn ($query) => $query->where('company_id', $companyId)),
                ],
                'name' => ['required', 'string'],
                'barcode' => [
                    'required',
                    'string',
                    Rule::unique('product_barcodes', 'barcode')->where(fn ($query) => $query->where('company_id', $companyId)),
                ],
                'category' => ['nullable', 'string'],
                'brand' => ['nullable', 'string'],
                'unit' => ['required', 'string'],
                'cost_price' => ['required', 'numeric', 'min:0'],
                'selling_price' => ['required', 'numeric', 'min:0'],
                'wholesale_price' => ['nullable', 'numeric', 'min:0'],
                'is_taxable' => ['nullable', 'boolean'],
                'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNumber}: ".$validator->errors()->first();
                $rowNumber++;

                continue;
            }

            $sku = (string) $data['sku'];
            $barcodeValue = (string) $data['barcode'];

            if (isset($seenSkus[$sku])) {
                $errors[] = "Row {$rowNumber}: duplicate SKU '{$sku}' in CSV.";
                $rowNumber++;

                continue;
            }

            if (isset($seenBarcodes[$barcodeValue])) {
                $errors[] = "Row {$rowNumber}: duplicate barcode '{$barcodeValue}' in CSV.";
                $rowNumber++;

                continue;
            }

            $seenSkus[$sku] = true;
            $seenBarcodes[$barcodeValue] = true;

            $unit = Unit::query()->where('short_name', $data['unit'])->first();

            if (! $unit) {
                $errors[] = "Row {$rowNumber}: unknown unit '{$data['unit']}'.";
                $rowNumber++;

                continue;
            }

            $category = filled($data['category'] ?? null)
                ? Category::query()->where('company_id', $companyId)->where('name', $data['category'])->first()
                : null;

            if (filled($data['category'] ?? null) && ! $category) {
                $errors[] = "Row {$rowNumber}: unknown category '{$data['category']}' for the selected company.";
                $rowNumber++;

                continue;
            }

            $brand = filled($data['brand'] ?? null)
                ? Brand::query()->where('company_id', $companyId)->where('name', $data['brand'])->first()
                : null;

            if (filled($data['brand'] ?? null) && ! $brand) {
                $errors[] = "Row {$rowNumber}: unknown brand '{$data['brand']}' for the selected company.";
                $rowNumber++;

                continue;
            }

            DB::transaction(function () use ($companyId, $data, $unit, $category, $brand, &$created): void {
                $product = Product::query()->create([
                    'company_id' => $companyId,
                    'category_id' => $category?->id,
                    'brand_id' => $brand?->id,
                    'unit_id' => $unit->id,
                    'sku' => $data['sku'],
                    'name' => $data['name'],
                    'description' => ($data['description'] ?? null) ?: null,
                    'cost_price' => $data['cost_price'],
                    'selling_price' => $data['selling_price'],
                    'wholesale_price' => ($data['wholesale_price'] ?? null) ?: null,
                    'tax_rate' => 0,
                    'is_taxable' => ! array_key_exists('is_taxable', $data)
                        || $data['is_taxable'] === ''
                        || filter_var($data['is_taxable'], FILTER_VALIDATE_BOOL),
                    'minimum_stock' => isset($data['minimum_stock']) && $data['minimum_stock'] !== '' ? $data['minimum_stock'] : 0,
                    'allow_negative_stock' => false,
                    'track_inventory' => true,
                    'is_active' => true,
                ]);

                ProductBarcode::query()->create([
                    'product_id' => $product->id,
                    'company_id' => $companyId,
                    'barcode' => $data['barcode'],
                    'is_primary' => true,
                ]);

                $created++;
            });

            $rowNumber++;
        }

        return [
            'created' => $created,
            'errors' => $errors,
        ];
    }
}
