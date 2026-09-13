<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SplFileObject;

class CsvDataImportService
{
    /** @var array<string, list<string>> */
    private const HEADERS = [
        'products' => ['sku', 'name', 'barcode', 'category', 'brand', 'unit', 'cost_price', 'selling_price', 'is_taxable', 'minimum_stock', 'initial_quantity', 'opening_unit_cost'],
        'categories' => ['name', 'code', 'parent_code', 'description'],
        'suppliers' => ['code', 'name', 'contact_person', 'phone', 'email', 'address', 'city', 'credit_limit', 'payment_terms_days', 'opening_balance'],
        'customers' => ['code', 'name', 'phone', 'email', 'address', 'city', 'credit_limit', 'opening_balance'],
    ];

    /** @return array{headers:list<string>,example:list<string>} */
    public function template(string $type): array
    {
        $headers = self::HEADERS[$type] ?? throw new InvalidArgumentException('Unsupported import type.');

        return [
            'headers' => $headers,
            'example' => match ($type) {
                'products' => ['SKU-001', 'Example Product', '1234567890123', 'Accessories', 'Generic', 'pcs', '10.00', '15.00', 'yes', '2', '12', '10.00'],
                'categories' => ['Accessories', 'ACCESS', '', 'Optional description'],
                'suppliers' => ['SUP-001', 'Example Supplier', 'Contact Name', '7000000', 'supplier@example.com', 'Address', 'Male', '0', '30', '0'],
                'customers' => ['CUS-001', 'Example Customer', '7000001', 'customer@example.com', 'Address', 'Male', '0', '0'],
            },
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,valid:int,duplicates:int,invalid:int,errors:list<string>} */
    public function preview(string $companyId, string $type, string $path, ?string $warehouseId = null): array
    {
        $rows = $this->read($type, $path);
        $seen = [];
        $preview = [];
        $errors = [];
        $valid = $duplicates = $invalid = 0;

        foreach ($rows as $row) {
            $result = $this->validateRow($companyId, $type, $row, $seen, $warehouseId);
            $preview[] = $result + [
                'row' => $row['row'],
                // Keep the uploaded values so the user can verify the import before committing it.
                'data' => array_diff_key($row, ['row' => true]),
            ];

            match ($result['status']) {
                'ready' => $valid++,
                'duplicate' => $duplicates++,
                default => $invalid++,
            };

            if ($result['status'] !== 'ready') {
                $errors[] = "Row {$row['row']}: {$result['message']}";
            }
        }

        return [
            'rows' => array_slice($preview, 0, 100),
            'total' => count($preview),
            'valid' => $valid,
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'errors' => $errors,
        ];
    }

    /** @return array{created:int,skipped:int,errors:list<string>} */
    public function import(string $companyId, string $type, string $path, ?string $warehouseId = null): array
    {
        $rows = $this->read($type, $path);
        $seen = [];
        $created = $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $result = $this->validateRow($companyId, $type, $row, $seen, $warehouseId);

            if ($result['status'] !== 'ready') {
                $skipped++;
                $errors[] = "Row {$row['row']}: {$result['message']}";

                continue;
            }

            DB::transaction(function () use ($companyId, $type, $row, $warehouseId): void {
                match ($type) {
                    'products' => $this->createProduct($companyId, $row, $warehouseId),
                    'categories' => $this->createCategory($companyId, $row),
                    'suppliers' => Supplier::query()->create($this->attributes($companyId, $row, ['code', 'name', 'contact_person', 'phone', 'email', 'address', 'city', 'credit_limit', 'payment_terms_days', 'opening_balance'])),
                    'customers' => Customer::query()->create($this->attributes($companyId, $row, ['code', 'name', 'phone', 'email', 'address', 'city', 'credit_limit', 'opening_balance']) + ['is_walk_in' => false]),
                };
            });

            $created++;
        }

        return compact('created', 'skipped', 'errors');
    }

    /** @return list<array<string,string|int>> */
    private function read(string $type, string $path): array
    {
        $knownHeaders = self::HEADERS[$type] ?? throw new InvalidArgumentException('Unsupported import type.');
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('CSV file is not readable.');
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), $file->fgetcsv() ?: []);
        $requiredHeaders = match ($type) {
            'products' => ['sku', 'name'],
            'categories' => ['name'],
            default => ['code', 'name'],
        };

        foreach ($requiredHeaders as $header) {
            if (! in_array($header, $headers, true)) {
                throw new InvalidArgumentException("Missing required CSV column: {$header}");
            }
        }

        $rows = [];
        $line = 1;
        while (! $file->eof()) {
            $line++;
            $values = $file->fgetcsv();
            if (! is_array($values) || $values === [null]) {
                continue;
            }
            $values = array_pad($values, count($headers), '');
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }
            $data = array_combine($headers, $values);
            if ($data === false) {
                continue;
            }
            $rows[] = array_replace(
                array_fill_keys($knownHeaders, ''),
                array_map(fn ($value) => trim((string) $value), $data),
                ['row' => $line],
            );
        }

        return $rows;
    }

    /** @param array<string,bool> $seen @param array<string,string|int> $row @return array{status:string,message:string} */
    private function validateRow(string $companyId, string $type, array $row, array &$seen, ?string $warehouseId): array
    {
        $key = match ($type) {
            'products' => $row['sku'] ?? '', default => $row['code'] ?: $row['name']
        };
        if ($key === '' || ($type === 'products' && ($row['name'] ?? '') === '')) {
            return ['status' => 'invalid', 'message' => 'Required fields are missing.'];
        }
        if (isset($seen[$type.':'.$key])) {
            return ['status' => 'duplicate', 'message' => "Duplicate {$key} in this CSV; it will not be imported."];
        }
        $seen[$type.':'.$key] = true;

        if ($type === 'products') {
            $barcode = $this->barcode($row);

            if ($barcode && isset($seen['products-barcode:'.$barcode])) {
                return ['status' => 'duplicate', 'message' => "Duplicate barcode '{$barcode}' in this CSV; it will not be imported."];
            }
            if ($barcode) {
                $seen['products-barcode:'.$barcode] = true;
            }

            $unit = $this->unitShortName($row);
            if (filled($row['unit']) && ! Unit::query()->whereRaw('LOWER(short_name) = ?', [strtolower($unit)])->exists()) {
                return ['status' => 'invalid', 'message' => "Unknown unit '{$row['unit']}'."];
            }

            foreach (['cost_price', 'selling_price', 'minimum_stock', 'opening_unit_cost'] as $field) {
                if (filled($row[$field]) && (! is_numeric($row[$field]) || (float) $row[$field] < 0)) {
                    return ['status' => 'invalid', 'message' => "{$field} must be a non-negative number."];
                }
            }

            if (filled($row['is_taxable']) && ! in_array(strtolower((string) $row['is_taxable']), ['1', '0', 'true', 'false', 'yes', 'no'], true)) {
                return ['status' => 'invalid', 'message' => 'is_taxable must be yes/no, true/false, or 1/0.'];
            }

            if (Product::query()->where('company_id', $companyId)->where('sku', $row['sku'])->exists() || ($barcode && ProductBarcode::query()->where('company_id', $companyId)->where('barcode', $barcode)->exists())) {
                return ['status' => 'duplicate', 'message' => 'SKU or barcode already exists; it will not be overwritten.'];
            }
            if (filled($row['initial_quantity'] ?? null) && (! $warehouseId || ! is_numeric($row['initial_quantity']) || (float) $row['initial_quantity'] < 0)) {
                return ['status' => 'invalid', 'message' => 'Opening quantity requires a warehouse and a non-negative number.'];
            }
        } elseif ($type === 'categories' && (Category::query()->where('company_id', $companyId)->where('name', $row['name'])->exists() || (filled($row['code']) && Category::query()->where('company_id', $companyId)->where('code', $row['code'])->exists()))) {
            return ['status' => 'duplicate', 'message' => 'Category name or code already exists; it will not be overwritten.'];
        } elseif ($type === 'suppliers' && Supplier::query()->where('company_id', $companyId)->where('code', $row['code'])->exists()) {
            return ['status' => 'duplicate', 'message' => 'Supplier code already exists; it will not be overwritten.'];
        } elseif ($type === 'customers' && Customer::query()->where('company_id', $companyId)->where('code', $row['code'])->exists()) {
            return ['status' => 'duplicate', 'message' => 'Customer code already exists; it will not be overwritten.'];
        }

        return ['status' => 'ready', 'message' => 'Ready to import.'];
    }

    /** @param array<string,string|int> $row */
    private function createProduct(string $companyId, array $row, ?string $warehouseId): void
    {
        $category = filled($row['category'] ?? null) ? Category::query()->firstOrCreate(['company_id' => $companyId, 'name' => $row['category']], ['is_active' => true]) : null;
        $brand = filled($row['brand'] ?? null) ? Brand::query()->firstOrCreate(['company_id' => $companyId, 'name' => $row['brand']], ['is_active' => true]) : null;
        $unit = $this->resolveUnit($row);
        $product = Product::query()->create([
            'company_id' => $companyId, 'category_id' => $category?->id, 'brand_id' => $brand?->id, 'unit_id' => $unit->id,
            'sku' => $row['sku'], 'name' => $row['name'], 'cost_price' => $row['cost_price'] ?: 0, 'selling_price' => $row['selling_price'] ?: 0,
            'tax_rate' => 0, 'is_taxable' => blank($row['is_taxable'] ?? null) || in_array(strtolower((string) $row['is_taxable']), ['1', 'true', 'yes'], true),
            'minimum_stock' => $row['minimum_stock'] ?: 0, 'track_inventory' => true, 'is_active' => true,
        ]);
        if ($barcode = $this->barcode($row)) {
            ProductBarcode::query()->create(['company_id' => $companyId, 'product_id' => $product->id, 'barcode' => $barcode, 'is_primary' => true]);
        }
        if ($warehouseId && (float) ($row['initial_quantity'] ?? 0) > 0) {
            app(InventoryService::class)->setOpeningStock($companyId, $warehouseId, $product->id, $row['initial_quantity'], $row['opening_unit_cost'] ?: $row['cost_price']);
        }
    }

    /** @param array<string,string|int> $row */
    private function barcode(array $row): ?string
    {
        $barcode = trim((string) ($row['barcode'] ?? ''));

        return $barcode === '' || $barcode === '0' ? null : $barcode;
    }

    /** @param array<string,string|int> $row */
    private function unitShortName(array $row): string
    {
        return strtolower(trim((string) ($row['unit'] ?? ''))) ?: 'pcs';
    }

    /** @param array<string,string|int> $row */
    private function resolveUnit(array $row): Unit
    {
        $shortName = $this->unitShortName($row);
        $unit = Unit::query()->whereRaw('LOWER(short_name) = ?', [$shortName])->first();

        if ($unit) {
            return $unit;
        }

        // A compact product CSV defaults to pieces, even for a new empty catalog.
        return Unit::query()->create(['name' => 'Piece', 'short_name' => 'pcs', 'precision' => 0, 'is_active' => true]);
    }

    /** @param array<string,string|int> $row */
    private function createCategory(string $companyId, array $row): void
    {
        $parent = filled($row['parent_code'] ?? null) ? Category::query()->where('company_id', $companyId)->where('code', $row['parent_code'])->first() : null;
        Category::query()->create(['company_id' => $companyId, 'name' => $row['name'], 'code' => $row['code'] ?: null, 'parent_id' => $parent?->id, 'description' => $row['description'] ?: null, 'is_active' => true]);
    }

    /** @param array<string,string|int> $row @param list<string> $fields @return array<string,mixed> */
    private function attributes(string $companyId, array $row, array $fields): array
    {
        $values = ['company_id' => $companyId, 'is_active' => true];
        foreach ($fields as $field) {
            $values[$field] = $row[$field] === '' ? null : $row[$field];
        }

        return $values;
    }
}
