<?php

namespace App\Models;

use App\Enums\TaxCategory;
use App\Services\ProductSearchService;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'category_id',
        'brand_id',
        'unit_id',
        'sku',
        'name',
        'description',
        'short_description',
        'slug',
        'images',
        'cost_price',
        'selling_price',
        'wholesale_price',
        'tax_rate',
        'is_taxable',
        'tax_category',
        'minimum_stock',
        'allow_negative_stock',
        'track_inventory',
        'is_active',
        'sale_price',
        'show_online',
        'is_featured',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'wholesale_price' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'is_taxable' => 'boolean',
            'tax_category' => TaxCategory::class,
            'minimum_stock' => 'decimal:4',
            'allow_negative_stock' => 'boolean',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'sale_price' => 'decimal:4',
            'images' => 'array',
            'show_online' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            // SQLite test databases cannot alter this foreign key; production uses its cascade constraint.
            if ($product->getConnection()->getDriverName() === 'sqlite') {
                $product->branchPrices()->delete();
            }
        });

        static::saving(function (Product $product): void {
            if ($product->isDirty('tax_category')) {
                $product->is_taxable = $product->tax_category === TaxCategory::StandardRated;
            } elseif ($product->isDirty('is_taxable')) {
                $product->tax_category = $product->is_taxable
                    ? TaxCategory::StandardRated
                    : TaxCategory::Exempt;
            }

            if (blank($product->slug) || $product->isDirty(['name', 'sku'])) {
                $base = Str::slug("{$product->name}-{$product->sku}") ?: Str::lower($product->sku);
                $slug = $base;
                $suffix = 2;

                while (static::query()->where('company_id', $product->company_id)->where('slug', $slug)->whereKeyNot($product->id)->exists()) {
                    $slug = "{$base}-{$suffix}";
                    $suffix++;
                }

                $product->slug = $slug;
            }

            foreach (['cost_price', 'selling_price', 'wholesale_price', 'sale_price', 'tax_rate', 'minimum_stock'] as $field) {
                $value = $product->{$field};

                if ($value !== null && (float) $value < 0) {
                    throw new InvalidArgumentException("Product {$field} cannot be negative.");
                }
            }

            if ($product->category_id) {
                $category = Category::query()->find($product->category_id);

                if (! $category || $category->company_id !== $product->company_id) {
                    throw new InvalidArgumentException('Product category must belong to the same company.');
                }
            }

            if ($product->brand_id) {
                $brand = Brand::query()->find($product->brand_id);

                if (! $brand || $brand->company_id !== $product->company_id) {
                    throw new InvalidArgumentException('Product brand must belong to the same company.');
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function effectiveTaxRate(): float
    {
        if (($this->tax_category ?? TaxCategory::StandardRated) !== TaxCategory::StandardRated) {
            return 0.0;
        }

        return (float) ($this->relationLoaded('company')
            ? $this->company?->default_tax_rate
            : $this->company()->value('default_tax_rate'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function primaryBarcode(): HasOne
    {
        return $this->hasOne(ProductBarcode::class)->where('is_primary', true);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function branchPrices(): HasMany
    {
        return $this->hasMany(ProductBranchPrice::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockCountItems(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVisibleOnline(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('show_online', true);
    }

    public static function searchForCompany(string $companyId, string $term, array $filters = []): Builder
    {
        return app(ProductSearchService::class)->search($companyId, $term, $filters);
    }

    public static function findByBarcode(string $companyId, string $barcode): ?self
    {
        return app(ProductSearchService::class)->findByBarcode($companyId, $barcode);
    }

    public static function findBySku(string $companyId, string $sku): ?self
    {
        return app(ProductSearchService::class)->findBySku($companyId, $sku);
    }

    public static function searchByName(string $companyId, string $query, array $filters = []): Builder
    {
        return app(ProductSearchService::class)->searchByName($companyId, $query, $filters);
    }
}
