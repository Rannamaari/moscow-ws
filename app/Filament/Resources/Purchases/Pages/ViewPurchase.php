<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnItem;
use App\Services\PurchaseService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => PurchaseResource::canEdit($this->getRecord())),
            Action::make('receive_items')
                ->label('Receive Into Inventory')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->modalHeading('Receive Purchase Items Into Inventory')
                ->modalDescription('The remaining quantities are prefilled. Confirm the physical quantities received, then submit to update stock.')
                ->visible(fn (): bool => auth()->user()?->can('purchases.receive')
                    && in_array($this->getRecord()->status, [PurchaseStatus::Ordered, PurchaseStatus::PartiallyReceived], true))
                ->form([
                    Repeater::make('items')
                        ->label('Receipt Lines')
                        ->default(fn (): array => $this->receiptDefaults())
                        ->schema([
                            Hidden::make('purchase_item_id'),
                            TextInput::make('product')->disabled()->dehydrated(false),
                            TextInput::make('ordered')->disabled()->dehydrated(false),
                            TextInput::make('received')->disabled()->dehydrated(false),
                            TextInput::make('remaining')->disabled()->dehydrated(false),
                            TextInput::make('receive_now')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('unit_cost')->disabled()->dehydrated(false),
                        ])
                        ->columns(6)
                        ->reorderable(false)
                        ->addable(false)
                        ->deletable(false),
                    DatePicker::make('received_at')->default(now()),
                ])
                ->action(function (array $data): void {
                    $purchase = $this->freshPurchaseRecord();
                    $itemsById = $purchase->items->keyBy('id');
                    $errors = [];

                    foreach ($data['items'] ?? [] as $index => $itemData) {
                        $receiveNow = (float) ($itemData['receive_now'] ?? 0);

                        if ($receiveNow <= 0) {
                            continue;
                        }

                        /** @var PurchaseItem|null $purchaseItem */
                        $purchaseItem = $itemsById->get($itemData['purchase_item_id'] ?? null);

                        if (! $purchaseItem) {
                            $errors["items.{$index}.receive_now"] = 'This purchase line no longer exists.';

                            continue;
                        }

                        $remaining = max(0, round((float) $purchaseItem->ordered_quantity - (float) $purchaseItem->received_quantity, 4));

                        if ($receiveNow > $remaining + 0.0001) {
                            $errors["items.{$index}.receive_now"] = sprintf(
                                'Only %s remaining for %s.',
                                number_format($remaining, 4, '.', ''),
                                $purchaseItem->product?->name ?? 'this item',
                            );
                        }
                    }

                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }

                    $quantities = collect($data['items'] ?? [])
                        ->filter(fn (array $item): bool => (float) ($item['receive_now'] ?? 0) > 0)
                        ->mapWithKeys(fn (array $item): array => [$item['purchase_item_id'] => $item['receive_now']])
                        ->all();

                    if ($quantities === []) {
                        throw ValidationException::withMessages([
                            'items' => 'Enter at least one positive received quantity.',
                        ]);
                    }

                    app(PurchaseService::class)->receivePurchase(
                        $this->getRecord()->id,
                        $quantities,
                        (string) auth()->id(),
                        isset($data['received_at']) ? Carbon::parse($data['received_at']) : now(),
                    );

                    $this->record->refresh();
                    Notification::make()->success()->title('Purchase receipt recorded.')->send();
                }),
            Action::make('record_payment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => auth()->user()?->can('purchases.pay') && (float) $this->getRecord()->balance_due > 0.0001 && $this->getRecord()->status !== PurchaseStatus::Cancelled)
                ->form([
                    TextInput::make('amount')
                        ->required()
                        ->numeric()
                        ->minValue(0.0001)
                        ->helperText(fn (): string => sprintf(
                            'Purchase total: MVR %s | Already paid: MVR %s | Balance due: MVR %s',
                            number_format((float) $this->getRecord()->grand_total, 4, '.', ''),
                            number_format((float) $this->getRecord()->paid_total, 4, '.', ''),
                            number_format((float) $this->getRecord()->balance_due, 4, '.', ''),
                        )),
                    Select::make('payment_method')
                        ->required()
                        ->options([
                            'cash' => 'Cash',
                            'bank_transfer' => 'Bank Transfer',
                            'card' => 'Card',
                            'other' => 'Other',
                        ]),
                    DatePicker::make('paid_at')->default(now()),
                    TextInput::make('reference')->maxLength(255),
                    Textarea::make('notes')->rows(3),
                ])
                ->action(function (array $data): void {
                    app(PurchaseService::class)->recordPayment(
                        $this->getRecord()->id,
                        $data['amount'],
                        $data['payment_method'],
                        [
                            'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                            'reference' => $data['reference'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'created_by' => auth()->id(),
                        ],
                    );

                    $this->record->refresh();
                    Notification::make()->success()->title('Purchase payment recorded.')->send();
                }),
            Action::make('purchase_return')
                ->label('Purchase Return')
                ->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => auth()->user()?->can('purchases.return') && $this->hasReturnableItems())
                ->form([
                    Repeater::make('items')
                        ->label('Return Lines')
                        ->default(fn (): array => $this->returnDefaults())
                        ->schema([
                            Hidden::make('purchase_item_id'),
                            TextInput::make('product')->disabled()->dehydrated(false),
                            TextInput::make('received')->disabled()->dehydrated(false),
                            TextInput::make('previously_returned')->disabled()->dehydrated(false),
                            TextInput::make('returnable')->disabled()->dehydrated(false),
                            TextInput::make('return_now')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                        ->columns(5)
                        ->reorderable(false)
                        ->addable(false)
                        ->deletable(false),
                    DatePicker::make('return_date')->default(now()),
                    Textarea::make('notes')->rows(3),
                ])
                ->action(function (array $data): void {
                    $quantities = collect($data['items'] ?? [])
                        ->filter(fn (array $item): bool => (float) ($item['return_now'] ?? 0) > 0)
                        ->mapWithKeys(fn (array $item): array => [$item['purchase_item_id'] => $item['return_now']])
                        ->all();

                    if ($quantities === []) {
                        throw ValidationException::withMessages([
                            'items' => 'Enter at least one positive return quantity.',
                        ]);
                    }

                    app(PurchaseService::class)->returnPurchase(
                        $this->getRecord()->id,
                        $quantities,
                        [
                            'return_date' => $data['return_date'] ?? now(config('app.business_timezone'))->toDateString(),
                            'notes' => $data['notes'] ?? null,
                            'created_by' => auth()->id(),
                        ],
                    );

                    $this->record->refresh();
                    Notification::make()->success()->title('Purchase return recorded.')->send();
                }),
            Action::make('cancel_purchase')
                ->label('Cancel Purchase')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (): bool => auth()->user()?->can('purchases.create')
                    && in_array($this->getRecord()->status, [PurchaseStatus::Draft, PurchaseStatus::Ordered], true))
                ->requiresConfirmation()
                ->form([
                    Textarea::make('notes')
                        ->label('Cancellation Notes')
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    app(PurchaseService::class)->cancelPurchase(
                        $this->getRecord()->id,
                        (string) auth()->id(),
                        $data['notes'] ?? null,
                    );

                    $this->record->refresh();
                    Notification::make()->success()->title('Purchase cancelled.')->send();
                }),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receiptDefaults(): array
    {
        $purchase = $this->freshPurchaseRecord();

        return $purchase->items->map(function ($item): array {
            $remaining = max(0, (float) $item->ordered_quantity - (float) $item->received_quantity);

            return [
                'purchase_item_id' => $item->id,
                'product' => $item->product?->name,
                'ordered' => number_format((float) $item->ordered_quantity, 4, '.', ''),
                'received' => number_format((float) $item->received_quantity, 4, '.', ''),
                'remaining' => number_format($remaining, 4, '.', ''),
                'receive_now' => $remaining > 0 ? number_format($remaining, 4, '.', '') : '0.0000',
                'unit_cost' => number_format((float) $item->unit_cost, 4, '.', ''),
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function returnDefaults(): array
    {
        $purchase = $this->freshPurchaseRecord();
        $returnedByItem = PurchaseReturnItem::query()
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
            ->whereIn('purchase_item_id', $purchase->items->pluck('id'))
            ->groupBy('purchase_item_id')
            ->pluck('returned_quantity', 'purchase_item_id');

        return $purchase->items->map(function ($item) use ($returnedByItem): array {
            $alreadyReturned = (float) ($returnedByItem[$item->id] ?? 0);
            $returnable = max(0, (float) $item->received_quantity - $alreadyReturned);

            return [
                'purchase_item_id' => $item->id,
                'product' => $item->product?->name,
                'received' => number_format((float) $item->received_quantity, 4, '.', ''),
                'previously_returned' => number_format($alreadyReturned, 4, '.', ''),
                'returnable' => number_format($returnable, 4, '.', ''),
                'return_now' => '0.0000',
            ];
        })->all();
    }

    private function hasReturnableItems(): bool
    {
        $purchase = $this->freshPurchaseRecord();
        $returnedByItem = PurchaseReturnItem::query()
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
            ->whereIn('purchase_item_id', $purchase->items->pluck('id'))
            ->groupBy('purchase_item_id')
            ->pluck('returned_quantity', 'purchase_item_id');

        return $purchase->items->contains(fn ($item): bool => ((float) $item->received_quantity - (float) ($returnedByItem[$item->id] ?? 0)) > 0.0001);
    }

    private function freshPurchaseRecord(): Purchase
    {
        /** @var Purchase $purchase */
        $purchase = Purchase::query()
            ->with(['items.product', 'payments'])
            ->findOrFail($this->getRecord()->id);

        return $purchase;
    }
}
