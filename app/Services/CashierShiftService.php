<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Exceptions\TransactionException;
use App\Models\CashierShift;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashierShiftService
{
    public function __construct(
        private readonly NumberSequenceService $numberSequenceService,
        private readonly TelegramSalesBotService $telegramSalesBot,
    ) {}

    public function activeFor(array $context, string $cashierId): ?CashierShift
    {
        return CashierShift::query()
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('warehouse_id', $context['warehouse_id'])
            ->where('cashier_id', $cashierId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    public function open(array $context, User $cashier, float $openingCash, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($context, $cashier, $openingCash, $notes): CashierShift {
            $existing = CashierShift::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->where('warehouse_id', $context['warehouse_id'])
                ->where('cashier_id', $cashier->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $shift = CashierShift::query()->create([
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch_id'],
                'warehouse_id' => $context['warehouse_id'],
                'cashier_id' => $cashier->id,
                'shift_number' => $this->numberSequenceService->next($context['company_id'], 'cashier_shift'),
                'currency' => $context['branch']->currency,
                'status' => 'open',
                'opening_cash' => $this->decimal($openingCash),
                'opening_notes' => $notes,
                'opened_at' => now(),
            ]);

            $shiftId = $shift->id;
            DB::afterCommit(fn () => $this->telegramSalesBot->notifyShiftOpened($shiftId));

            return $shift;
        });
    }

    public function close(CashierShift $shift, array $context, User $cashier, float $closingCash, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $context, $cashier, $closingCash, $notes): CashierShift {
            $shift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($shift->status !== 'open') {
                throw new TransactionException('This cashier shift has already been closed.');
            }

            if ($shift->cashier_id !== $cashier->id || $shift->company_id !== $context['company_id'] || $shift->branch_id !== $context['branch_id'] || $shift->warehouse_id !== $context['warehouse_id']) {
                throw new TransactionException('This shift does not belong to the current POS assignment.');
            }

            $saleStatuses = [SaleStatus::Completed->value, SaleStatus::PartiallyRefunded->value, SaleStatus::Refunded->value];
            $sales = Sale::query()
                ->where('cashier_shift_id', $shift->id)
                ->whereIn('status', $saleStatuses)
                ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(discount_total), 0) as discount_total, COALESCE(SUM(tax_total), 0) as tax_total, COALESCE(SUM(grand_total), 0) as grand_total, COALESCE(SUM(paid_total), 0) as paid_total, COALESCE(SUM(balance_due), 0) as balance_due')
                ->first();

            $salePayments = SalePayment::query()
                ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                ->where('sales.cashier_shift_id', $shift->id)
                ->whereIn('sales.status', $saleStatuses)
                ->selectRaw('sale_payments.payment_method, COALESCE(SUM(sale_payments.amount), 0) as amount, COALESCE(SUM(sale_payments.amount_tendered), 0) as tendered, COALESCE(SUM(sale_payments.change_due), 0) as change_due')
                ->groupBy('sale_payments.payment_method')
                ->orderBy('sale_payments.payment_method')
                ->get()
                ->map(fn ($payment): array => [
                    'method' => $payment->payment_method,
                    'amount' => $this->decimal((float) $payment->amount),
                    'tendered' => $this->decimal((float) $payment->tendered),
                    'change_due' => $this->decimal((float) $payment->change_due),
                ]);

            $creditCollections = CustomerPayment::query()
                ->where('cashier_shift_id', $shift->id)
                ->selectRaw('payment_method, COUNT(*) as payment_count, COALESCE(SUM(amount), 0) as amount')
                ->groupBy('payment_method')
                ->get();
            $payments = $salePayments
                ->concat($creditCollections->map(fn ($payment): array => [
                    'method' => $payment->payment_method,
                    'amount' => $this->decimal((float) $payment->amount),
                    'tendered' => $this->decimal((float) $payment->amount),
                    'change_due' => $this->decimal(0),
                ]))
                ->groupBy('method')
                ->map(fn ($methodPayments, string $method): array => [
                    'method' => $method,
                    'amount' => $this->decimal((float) $methodPayments->sum('amount')),
                    'tendered' => $this->decimal((float) $methodPayments->sum('tendered')),
                    'change_due' => $this->decimal((float) $methodPayments->sum('change_due')),
                ])
                ->sortBy('method')
                ->values()
                ->all();

            $creditCollectionsCount = (int) $creditCollections->sum('payment_count');
            $creditCollectionsTotal = (float) $creditCollections->sum('amount');

            $cashPayments = collect($payments)->firstWhere('method', 'cash');
            $cashReceived = (float) ($cashPayments['amount'] ?? 0);
            $returns = SaleReturn::query()
                ->where('company_id', $shift->company_id)
                ->where('warehouse_id', $shift->warehouse_id)
                ->where('created_by', $shift->cashier_id)
                ->whereBetween('created_at', [$shift->opened_at, now()])
                ->selectRaw('COUNT(*) as returns_count, COALESCE(SUM(grand_total), 0) as returns_total')
                ->first();
            $expectedCash = (float) $shift->opening_cash + $cashReceived;
            $variance = $closingCash - $expectedCash;
            $closedAt = now();

            $snapshot = [
                'generated_at' => $closedAt->copy()->timezone(config('app.business_timezone'))->toIso8601String(),
                'sales_count' => (int) ($sales->sales_count ?? 0),
                'subtotal' => $this->decimal((float) ($sales->subtotal ?? 0)),
                'discount_total' => $this->decimal((float) ($sales->discount_total ?? 0)),
                'tax_total' => $this->decimal((float) ($sales->tax_total ?? 0)),
                'grand_total' => $this->decimal((float) ($sales->grand_total ?? 0)),
                'paid_total' => $this->decimal((float) ($sales->paid_total ?? 0)),
                'balance_due' => $this->decimal((float) ($sales->balance_due ?? 0)),
                'credit_collections_count' => $creditCollectionsCount,
                'credit_collections_total' => $this->decimal($creditCollectionsTotal),
                'payments' => $payments,
                'cash_received' => $this->decimal($cashReceived),
                'returns_count' => (int) ($returns->returns_count ?? 0),
                'returns_total' => $this->decimal((float) ($returns->returns_total ?? 0)),
                'refund_note' => 'Refund payment methods are not recorded yet, so refunds are shown separately and are not deducted from expected cash.',
            ];

            $shift->forceFill([
                'status' => 'closed',
                'expected_cash' => $this->decimal($expectedCash),
                'closing_cash' => $this->decimal($closingCash),
                'cash_variance' => $this->decimal($variance),
                'closing_notes' => $notes,
                'report_snapshot' => $snapshot,
                'closed_at' => $closedAt,
            ])->save();

            $shiftId = $shift->id;
            DB::afterCommit(fn () => $this->telegramSalesBot->notifyShiftClosed($shiftId));

            return $shift->fresh(['company', 'branch', 'warehouse', 'cashier']);
        });
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
