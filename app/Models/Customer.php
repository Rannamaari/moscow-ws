<?php

namespace App\Models;

use App\Enums\CustomerTransactionType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'phone',
        'phone_verified_at',
        'email',
        'registration_number',
        'tax_number',
        'address',
        'city',
        'credit_limit',
        'payment_terms_days',
        'opening_balance',
        'notes',
        'is_active',
        'is_walk_in',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'payment_terms_days' => 'integer',
            'opening_balance' => 'decimal:4',
            'phone_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_walk_in' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->is_walk_in) {
                if (static::query()
                    ->where('company_id', $customer->company_id)
                    ->where('is_walk_in', true)
                    ->when($customer->exists, fn ($query) => $query->whereKeyNot($customer->id))
                    ->exists()) {
                    throw new \InvalidArgumentException('Only one walk-in customer is allowed per company.');
                }
            }
        });

        static::created(function (Customer $customer): void {
            if ((float) $customer->opening_balance !== 0.0) {
                CustomerTransaction::query()->create([
                    'company_id' => $customer->company_id,
                    'customer_id' => $customer->id,
                    'type' => CustomerTransactionType::OpeningBalance,
                    'amount' => $customer->opening_balance,
                    'reference_type' => self::class,
                    'reference_id' => $customer->id,
                    'reference_number' => $customer->code,
                    'description' => 'Opening balance',
                    'occurred_at' => now(),
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
