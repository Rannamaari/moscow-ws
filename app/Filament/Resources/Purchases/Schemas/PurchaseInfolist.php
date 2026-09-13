<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Models\Purchase;
use App\Models\StockMovement;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Purchase')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('purchase_number'),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('purchase_date')->date(),
                                TextEntry::make('supplier.name'),
                                TextEntry::make('supplier_invoice_number')->label('Supplier Invoice'),
                                TextEntry::make('branch.name'),
                                TextEntry::make('warehouse.name'),
                                TextEntry::make('expected_date')->date(),
                                TextEntry::make('creator.name')->label('Created By'),
                                TextEntry::make('receiver.name')->label('Last Received By'),
                                TextEntry::make('received_at')->dateTime(),
                                TextEntry::make('notes')->columnSpanFull(),
                            ]),
                    ]),
                Section::make('Purchase Totals')
                    ->description('Automatically calculated from the saved purchase lines.')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'xl' => 4,
                        ])
                            ->schema([
                                TextEntry::make('subtotal')->label('Subtotal')->money('MVR'),
                                TextEntry::make('discount_total')->label('Discount')->money('MVR'),
                                TextEntry::make('tax_total')
                                    ->label('Total GST')
                                    ->money('MVR')
                                    ->weight('bold')
                                    ->color('primary'),
                                TextEntry::make('shipping_total')->label('Shipping')->money('MVR'),
                                TextEntry::make('other_cost_total')->label('Other Costs')->money('MVR'),
                                TextEntry::make('grand_total')->label('Grand Total')->money('MVR')->weight('bold'),
                                TextEntry::make('paid_total')->label('Paid')->money('MVR'),
                                TextEntry::make('balance_due')->label('Balance Due')->money('MVR')->weight('bold'),
                            ]),
                    ]),
                Section::make('Items')
                    ->schema([
                        TextEntry::make('inventory_status')
                            ->label('Inventory Status')
                            ->state(function (Purchase $record): string {
                                $received = (float) $record->items->sum('received_quantity');
                                $ordered = (float) $record->items->sum('ordered_quantity');

                                if ($received <= 0) {
                                    return 'Not received — click Receive Into Inventory above to add stock.';
                                }

                                if ($received + 0.0001 < $ordered) {
                                    return 'Partially received — use Receive Into Inventory for the remaining items.';
                                }

                                return 'Fully received into inventory.';
                            })
                            ->badge()
                            ->color(fn (Purchase $record): string => (float) $record->items->sum('received_quantity') <= 0
                                ? 'warning'
                                : ((float) $record->items->sum('received_quantity') + 0.0001 < (float) $record->items->sum('ordered_quantity') ? 'info' : 'success'))
                            ->columnSpanFull(),
                        RepeatableEntry::make('items')
                            ->label('Ordered Products')
                            ->schema([
                                TextEntry::make('product.name')->label('Product')->weight('bold'),
                                TextEntry::make('product.sku')->label('SKU'),
                                TextEntry::make('ordered_quantity')->label('Ordered')->numeric(decimalPlaces: 2),
                                TextEntry::make('received_quantity')->label('Received')->numeric(decimalPlaces: 2),
                                TextEntry::make('remaining_quantity')
                                    ->label('Remaining')
                                    ->state(fn ($record): float => max(0, (float) $record->ordered_quantity - (float) $record->received_quantity))
                                    ->numeric(decimalPlaces: 2),
                                TextEntry::make('unit_cost')->label('Unit Cost')->money('MVR'),
                                TextEntry::make('tax_amount')->label('GST')->money('MVR'),
                                TextEntry::make('line_total')->label('Line Total')->money('MVR')->weight('bold'),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 4,
                                'xl' => 8,
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Receipt History')
                    ->schema([
                        TextEntry::make('receipt_history')
                            ->state(fn (Purchase $record): array => StockMovement::query()
                                ->with(['product', 'creator'])
                                ->where('reference_type', Purchase::class)
                                ->where('reference_id', $record->id)
                                ->orderBy('occurred_at')
                                ->get()
                                ->map(fn (StockMovement $movement): string => sprintf(
                                    '%s • %s • %s +%s',
                                    $movement->occurred_at?->format('d M H:i') ?? 'Unknown time',
                                    $movement->creator?->name ?? 'System',
                                    $movement->product?->name ?? 'Product',
                                    number_format((float) $movement->quantity, 4, '.', ''),
                                ))
                                ->all())
                            ->listWithLineBreaks(),
                    ])
                    ->visible(fn (Purchase $record): bool => StockMovement::query()
                        ->where('reference_type', Purchase::class)
                        ->where('reference_id', $record->id)
                        ->exists()),
                Section::make('Payments')
                    ->schema([
                        TextEntry::make('payments_summary')
                            ->state(fn (Purchase $record): array => $record->payments
                                ->sortBy('paid_at')
                                ->map(fn ($payment): string => sprintf(
                                    '%s • %s • MVR %s%s',
                                    $payment->paid_at?->format('d M Y') ?? 'Unknown date',
                                    strtoupper((string) $payment->payment_method),
                                    number_format((float) $payment->amount, 4, '.', ''),
                                    $payment->reference ? ' • '.$payment->reference : '',
                                ))
                                ->values()
                                ->all())
                            ->listWithLineBreaks(),
                    ])
                    ->visible(fn (Purchase $record): bool => $record->payments->isNotEmpty()),
                Section::make('Returns')
                    ->schema([
                        TextEntry::make('returns_summary')
                            ->state(fn (Purchase $record): array => $record->returns
                                ->sortBy('return_date')
                                ->map(fn ($return): string => sprintf(
                                    '%s • %s • MVR %s',
                                    optional($return->return_date)->format('d M Y') ?? 'Unknown date',
                                    $return->purchase_return_number,
                                    number_format((float) $return->grand_total, 4, '.', ''),
                                ))
                                ->values()
                                ->all())
                            ->listWithLineBreaks(),
                    ])
                    ->visible(fn (Purchase $record): bool => $record->returns->isNotEmpty()),
            ]);
    }
}
