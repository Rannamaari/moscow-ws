<?php

namespace App\Services;

use App\Enums\CustomerTransactionType;
use App\Exceptions\TransactionException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerLedgerService
{
    public function currentBalance(string $customerId, ?string $currency = null): string
    {
        $query = CustomerTransaction::query()
            ->where('customer_id', $customerId)
            ->when($currency, fn ($query) => $query->where('currency', $currency));

        $total = $query->sum('amount');

        return $this->formatDecimal($total);
    }

    public function recordTransaction(
        string $companyId,
        string $customerId,
        CustomerTransactionType $type,
        float|string $amount,
        array $attributes = [],
    ): CustomerTransaction {
        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->find($customerId);

        if (! $customer) {
            throw new TransactionException('Customer does not belong to the selected company.');
        }

        if (! is_numeric($amount)) {
            throw new TransactionException('Customer transaction amount must be numeric.');
        }

        return CustomerTransaction::query()->create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'type' => $type,
            'amount' => $this->formatDecimal($amount),
            'currency' => $attributes['currency'] ?? 'MVR',
            'reference_type' => $attributes['reference_type'] ?? null,
            'reference_id' => $attributes['reference_id'] ?? null,
            'reference_number' => $attributes['reference_number'] ?? null,
            'description' => $attributes['description'] ?? null,
            'created_by' => $attributes['created_by'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? Carbon::now(),
        ]);
    }

    public function recordPayment(
        string $companyId,
        string $customerId,
        float|string $amount,
        string $paymentMethod,
        array $attributes = [],
    ): CustomerPayment {
        $numericAmount = $this->normalizePositiveDecimal($amount, 'Customer payment amount');

        return DB::transaction(function () use ($companyId, $customerId, $numericAmount, $paymentMethod, $attributes): CustomerPayment {
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->find($customerId);

            if (! $customer) {
                throw new TransactionException('Customer does not belong to the selected company.');
            }

            $sale = null;

            if ($saleId = $attributes['sale_id'] ?? null) {
                $sale = Sale::query()
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->find($saleId);

                if (! $sale || $sale->customer_id !== $customer->id) {
                    throw new TransactionException('Sale does not belong to the selected customer and company.');
                }

                $saleBalance = (float) $sale->balance_due;

                if ($numericAmount > $saleBalance + 0.0001) {
                    if (! $this->sameCurrencyAmount($numericAmount, $saleBalance)) {
                        throw new TransactionException('Customer payment cannot exceed the outstanding balance on this sale.');
                    }

                    $numericAmount = $saleBalance;
                }
            }

            $currency = $sale?->currency ?? ($attributes['currency'] ?? 'MVR');
            $receivableBalance = (float) $this->currentBalance($customer->id, $currency);

            if ($numericAmount > $receivableBalance + 0.0001) {
                if (! $this->sameCurrencyAmount($numericAmount, $receivableBalance)) {
                    throw new TransactionException('Customer payment cannot exceed the receivable balance.');
                }

                $numericAmount = $receivableBalance;
            }

            $payment = CustomerPayment::query()->create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'sale_id' => $sale?->id,
                'cashier_shift_id' => $attributes['cashier_shift_id'] ?? null,
                'payment_method' => $paymentMethod,
                'currency' => $currency,
                'amount' => $this->formatDecimal($numericAmount),
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'paid_at' => $attributes['paid_at'] ?? now(),
                'created_by' => $attributes['created_by'] ?? null,
            ]);

            $this->recordTransaction($companyId, $customerId, CustomerTransactionType::Payment, -$numericAmount, [
                'reference_type' => $sale ? Sale::class : CustomerPayment::class,
                'reference_id' => $sale?->id ?? $payment->id,
                'reference_number' => $sale?->sale_number ?? $payment->id,
                'description' => $attributes['notes'] ?? 'Customer payment',
                'created_by' => $attributes['created_by'] ?? null,
                'occurred_at' => $attributes['paid_at'] ?? now(),
                'currency' => $currency,
            ]);

            if ($sale) {
                $sale->forceFill([
                    'paid_total' => $this->formatDecimal((float) $sale->paid_total + $numericAmount),
                    'balance_due' => $this->formatDecimal(max(0, (float) $sale->balance_due - $numericAmount)),
                ])->save();
            }

            return $payment;
        });
    }

    private function sameCurrencyAmount(float $first, float $second): bool
    {
        return abs(round($first, 2) - round($second, 2)) < 0.0001;
    }

    private function normalizePositiveDecimal(float|string $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new TransactionException("{$label} must be greater than zero.");
        }

        return round((float) $value, 4);
    }

    private function formatDecimal(float|string|int $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
