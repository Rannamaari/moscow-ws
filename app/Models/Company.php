<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'receipt_shop_name',
        'legal_name',
        'registration_number',
        'tax_number',
        'default_tax_rate',
        'gst_filing_frequency',
        'receipt_gst_label',
        'phone',
        'email',
        'address',
        'receipt_header',
        'receipt_footer',
        'receipt_show_address',
        'receipt_show_phone',
        'city',
        'country',
        'timezone',
        'currency',
        'is_active',
        'pos_test_mode',
        'website_enabled',
        'online_branch_id',
        'online_warehouse_id',
        'website_whatsapp',
        'website_social_links',
        'website_delivery_methods',
        'website_payment_methods',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_tax_rate' => 'decimal:4',
            'is_active' => 'boolean',
            'pos_test_mode' => 'boolean',
            'receipt_show_address' => 'boolean',
            'receipt_show_phone' => 'boolean',
            'website_enabled' => 'boolean',
            'website_social_links' => 'array',
            'website_delivery_methods' => 'array',
            'website_payment_methods' => 'array',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
