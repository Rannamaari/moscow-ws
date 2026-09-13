<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\CashierShift;
use App\Models\Sale;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramSalesBotService
{
    public function notifySale(string $saleId): void
    {
        $sale = Sale::query()->with(['items.product', 'payments', 'customer', 'creator', 'branch'])->find($saleId);

        if ($sale) {
            $this->broadcast($this->formatSale($sale, true));
        }
    }

    public function notifyShiftOpened(string $shiftId): void
    {
        $shift = CashierShift::query()->with(['cashier', 'branch', 'warehouse'])->find($shiftId);

        if (! $shift) {
            return;
        }

        $this->broadcast(implode("\n", [
            '🟢 Counter opened',
            "Shift: {$shift->shift_number}",
            'Cashier: '.($shift->cashier?->name ?? 'Unknown'),
            'Branch: '.($shift->branch?->name ?? 'Unknown'),
            'Opening cash: '.$this->money($shift->opening_cash, $shift->currency),
            'Opened: '.$this->dateTime($shift->opened_at),
            $shift->opening_notes ? 'Notes: '.$shift->opening_notes : null,
        ]));
    }

    public function notifyShiftClosed(string $shiftId): void
    {
        $shift = CashierShift::query()->with(['cashier', 'branch', 'sales.items.product', 'sales.payments'])->find($shiftId);

        if (! $shift) {
            return;
        }

        $snapshot = $shift->report_snapshot ?? [];
        $payments = collect($snapshot['payments'] ?? [])->map(
            fn (array $payment): string => ucfirst(str_replace('_', ' ', $payment['method'] ?? 'unknown')).': '.$this->money($payment['amount'] ?? 0, $shift->currency)
        )->implode("\n");

        $lines = [
            '🔴 Counter closed — End of day',
            "Shift: {$shift->shift_number}",
            'Cashier: '.($shift->cashier?->name ?? 'Unknown'),
            'Branch: '.($shift->branch?->name ?? 'Unknown'),
            'Opened: '.$this->dateTime($shift->opened_at),
            'Closed: '.$this->dateTime($shift->closed_at),
            'Sales: '.($snapshot['sales_count'] ?? $shift->sales->count()),
            'Sales total: '.$this->money($snapshot['grand_total'] ?? 0, $shift->currency),
            'Paid: '.$this->money($snapshot['paid_total'] ?? 0, $shift->currency),
            'Returns: '.($snapshot['returns_count'] ?? 0).' ('.$this->money($snapshot['returns_total'] ?? 0, $shift->currency).')',
            'Opening cash: '.$this->money($shift->opening_cash, $shift->currency),
            'Expected cash: '.$this->money($shift->expected_cash, $shift->currency),
            'Closing cash: '.$this->money($shift->closing_cash, $shift->currency),
            'Cash variance: '.$this->money($shift->cash_variance, $shift->currency),
            $payments !== '' ? "Payments:\n{$payments}" : 'Payments: None',
        ];

        if ($shift->sales->isNotEmpty()) {
            $lines[] = 'Sale details:';
            foreach ($shift->sales as $sale) {
                $lines[] = $this->formatSale($sale, false);
            }
        }

        $this->broadcast(implode("\n", array_filter($lines, fn ($line) => $line !== null && $line !== '')));
    }

    /** @param array<string, mixed> $update */
    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;

        if (! $chatId || ! $this->canUseCommands($chatId)) {
            Log::warning('Ignored unauthorized Telegram bot update.', [
                'chat_id' => $chatId,
                'username' => $message['from']['username'] ?? null,
            ]);

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        [$rawCommand, $argument] = array_pad(preg_split('/\s+/', $text, 2) ?: [], 2, '');
        $command = strtolower(strtok($rawCommand, '@') ?: '');

        match ($command) {
            '/start', '/help' => $this->send($chatId, "Moscow Traders Wholesale Sales Bot\n\n/today — today's sales summary\n/sales — latest 10 sales\n/sale SAL-000001 — full sale details"),
            '/today' => $this->send($chatId, $this->todaySummary()),
            '/sales' => $this->send($chatId, $this->recentSales()),
            '/sale' => $this->send($chatId, $this->saleLookup($argument)),
            default => $this->send($chatId, 'Unknown command. Send /help to see available commands.'),
        };
    }

    public function test(): int
    {
        return $this->broadcast('✅ Moscow Traders Wholesale Telegram sales notifications are working.');
    }

    /** @return array<string, mixed> */
    public function getMe(): array
    {
        return $this->request()->get($this->url('getMe'))->throw()->json();
    }

    /** @return array<string, mixed> */
    public function getUpdates(): array
    {
        return $this->request()->get($this->url('getUpdates'))->throw()->json();
    }

    public function setWebhook(string $url): array
    {
        return $this->request()->post($this->url('setWebhook'), [
            'url' => $url,
            'secret_token' => (string) config('services.telegram_sales.webhook_secret'),
            'allowed_updates' => ['message'],
        ])->throw()->json();
    }

    private function todaySummary(): string
    {
        $sales = Sale::query()->with(['items.product', 'payments'])
            ->whereIn('status', [SaleStatus::Completed, SaleStatus::PartiallyRefunded, SaleStatus::Refunded])
            ->whereBetween('completed_at', [
                now(config('app.business_timezone'))->startOfDay()->utc(),
                now(config('app.business_timezone'))->endOfDay()->utc(),
            ])->orderBy('completed_at')->get();

        if ($sales->isEmpty()) {
            return 'No sales recorded today.';
        }

        $pos = $sales->where('sales_channel', '!=', 'website');
        $web = $sales->where('sales_channel', 'website');
        $currency = $sales->first()->currency;
        $lines = [
            '📊 Today’s sales',
            'Total: '.$this->money($sales->sum('grand_total'), $currency).' ('.$sales->count().' sales)',
            'Shop/POS: '.$this->money($pos->sum('grand_total'), $currency).' ('.$pos->count().')',
            'Website: '.$this->money($web->sum('grand_total'), $currency).' ('.$web->count().')',
            '',
        ];
        foreach ($sales->take(20) as $sale) {
            $lines[] = $this->formatSale($sale, false);
        }

        return implode("\n", $lines);
    }

    private function recentSales(): string
    {
        $sales = Sale::query()->with(['items.product', 'payments'])
            ->whereIn('status', [SaleStatus::Completed, SaleStatus::PartiallyRefunded, SaleStatus::Refunded])
            ->latest('completed_at')->limit(10)->get();

        return $sales->isEmpty()
            ? 'No sales found.'
            : "Latest sales:\n\n".$sales->map(fn (Sale $sale): string => $this->formatSale($sale, false))->implode("\n");
    }

    private function saleLookup(string $number): string
    {
        if ($number === '') {
            return 'Usage: /sale SAL-000001';
        }

        $sale = Sale::query()->with(['items.product', 'payments', 'customer', 'creator', 'branch'])
            ->whereRaw('LOWER(sale_number) = ?', [strtolower($number)])->first();

        return $sale ? $this->formatSale($sale, true) : "Sale {$number} was not found.";
    }

    private function formatSale(Sale $sale, bool $detailed): string
    {
        $sale->loadMissing(['items.product', 'payments', 'customer', 'creator', 'branch']);
        $website = $sale->sales_channel === 'website';
        $lines = [
            ($website ? '🌐 Website sale' : '🧾 Shop/POS sale').' — '.$sale->sale_number,
            'Total: '.$this->money($sale->grand_total, $sale->currency),
            'Status: '.ucfirst(str_replace('_', ' ', $sale->status->value)),
        ];

        if ($website) {
            $lines[] = 'Order: '.ucfirst((string) ($sale->order_status ?: 'pending')).' | Payment: '.ucfirst((string) ($sale->payment_status ?: 'unpaid'));
            $lines[] = 'Delivery: '.ucfirst(str_replace('_', ' ', (string) ($sale->delivery_method ?: 'not specified')));
        }

        if ($detailed) {
            $lines[] = 'Customer: '.($sale->customer?->name ?? 'Walk-in customer');
            if ($sale->customer?->phone) {
                $lines[] = 'Phone: '.$sale->customer->phone;
            }
            $lines[] = 'Cashier: '.($sale->creator?->name ?? ($website ? 'Website' : 'Unknown'));
            $lines[] = 'Branch: '.($sale->branch?->name ?? 'Unknown');
            $lines[] = 'Items:';
            foreach ($sale->items as $item) {
                $name = $item->product?->name ?? $item->description;
                $lines[] = '• '.$this->quantity($item->quantity).' × '.$name.' — '.$this->money($item->line_total, $sale->currency);
            }
            $methods = $sale->payments->map(fn ($payment): string => ucfirst(str_replace('_', ' ', $payment->payment_method)))->unique()->implode(', ');
            $lines[] = 'Payment method: '.($methods ?: ucfirst(str_replace('_', ' ', (string) ($sale->website_payment_method ?: 'not recorded'))));
            if ($sale->delivery_address) {
                $lines[] = 'Address: '.$sale->delivery_address;
            }
        }

        $lines[] = 'Time: '.$this->dateTime($sale->completed_at ?? $sale->created_at);

        return implode("\n", $lines);
    }

    private function broadcast(string $message): int
    {
        if (! config('services.telegram_sales.enabled')) {
            return 0;
        }

        $sent = 0;
        foreach ($this->notificationChatIds() as $chatId) {
            try {
                $this->send($chatId, $message);
                $sent++;
            } catch (Throwable $exception) {
                Log::error('Telegram sales notification failed.', [
                    'chat_id' => $chatId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    private function send(string $chatId, string $message): void
    {
        if (! config('services.telegram_sales.enabled')) {
            return;
        }

        foreach (str_split($message, 4000) as $part) {
            $this->request()->post($this->url('sendMessage'), [
                'chat_id' => $chatId,
                'text' => $part,
                'disable_web_page_preview' => true,
            ])->throw();
        }
    }

    private function request(): PendingRequest
    {
        if (! config('services.telegram_sales.bot_token')) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return Http::acceptJson()->timeout((int) config('services.telegram_sales.timeout', 10));
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram_sales.bot_token').'/'.$method;
    }

    /** @return list<string> */
    private function notificationChatIds(): array
    {
        return array_map('strval', config('services.telegram_sales.notification_chat_ids', []));
    }

    /** @return list<string> */
    private function commandChatIds(): array
    {
        return array_map('strval', config('services.telegram_sales.command_chat_ids', []));
    }

    private function canUseCommands(string $chatId): bool
    {
        return in_array($chatId, $this->commandChatIds(), true);
    }

    private function money(float|string|null $amount, ?string $currency): string
    {
        return ($currency ?: 'MVR').' '.number_format((float) $amount, 2);
    }

    private function quantity(float|string $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 4, '.', ''), '0'), '.');
    }

    private function dateTime(?Carbon $date): string
    {
        return $date ? $date->timezone(config('app.business_timezone'))->format('d M Y, h:i A').' MVT' : 'Unknown';
    }
}
