<?php

namespace App\Models;

use App\Enums\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'warehouse_id',
        'cashier_shift_id',
        'customer_id',
        'customer_tax_number',
        'payment_terms_days',
        'sale_number',
        'status',
        'currency',
        'client_transaction_uuid',
        'sale_date',
        'due_date',
        'subtotal',
        'discount_total',
        'tax_total',
        'grand_total',
        'paid_total',
        'balance_due',
        'notes',
        'cancellation_reason',
        'cancellation_notes',
        'created_by',
        'cancelled_by',
        'completed_at',
        'voided_at',
        'cancelled_at',
        'receipt_snapshot',
        'sales_channel',
        'order_status',
        'payment_status',
        'website_payment_method',
        'delivery_method',
        'delivery_charge',
        'delivery_address',
        'tracking_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'sale_date' => 'date',
            'due_date' => 'date',
            'payment_terms_days' => 'integer',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'balance_due' => 'decimal:4',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'receipt_snapshot' => 'array',
            'delivery_charge' => 'decimal:4',
        ];
    }

    public function scopeReportable(Builder $query): Builder
    {
        return static::constrainToReportable($query);
    }

    public static function constrainToReportable(Builder $query, string $table = 'sales'): Builder
    {
        return $query
            ->whereIn("{$table}.status", [
                SaleStatus::Completed->value,
                SaleStatus::Refunded->value,
                SaleStatus::PartiallyRefunded->value,
            ])
            ->where(function (Builder $sales) use ($table): void {
                $sales->where(function (Builder $posSales) use ($table): void {
                    $posSales->whereNull("{$table}.sales_channel")
                        ->orWhere("{$table}.sales_channel", '!=', 'website');
                })->orWhere(function (Builder $websiteSales) use ($table): void {
                    $websiteSales->where("{$table}.sales_channel", 'website')
                        ->where("{$table}.order_status", 'completed')
                        ->where("{$table}.payment_status", 'paid');
                });
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function receiptPrintEvents(): HasMany
    {
        return $this->hasMany(ReceiptPrintEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
