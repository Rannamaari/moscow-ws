<?php

namespace App\Models;

use App\Enums\TaxCategory;
use Database\Factories\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_id',
        'company_id',
        'product_id',
        'description',
        'ordered_quantity',
        'received_quantity',
        'unit_cost',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'tax_category',
        'price_includes_tax',
        'input_tax_claimable',
        'line_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'tax_category' => TaxCategory::class,
            'price_includes_tax' => 'boolean',
            'input_tax_claimable' => 'boolean',
            'line_total' => 'decimal:4',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
