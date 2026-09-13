<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'cashier_id', 'shift_number', 'currency', 'status',
        'opening_cash', 'expected_cash', 'closing_cash', 'cash_variance', 'opening_notes', 'closing_notes',
        'report_snapshot', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'closing_cash' => 'decimal:4',
            'cash_variance' => 'decimal:4',
            'report_snapshot' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
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

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
