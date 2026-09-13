<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\TaxCategory;
use App\Filament\Resources\InventoryOverview\InventoryOverviewResource;
use App\Models\Product;
use App\Services\InventoryQueryService;
use App\Support\InventoryStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('sku'),
                                TextEntry::make('name'),
                                TextEntry::make('primaryBarcode.barcode')->label('Primary Barcode'),
                                TextEntry::make('category.name'),
                                TextEntry::make('brand.name'),
                                TextEntry::make('unit.name'),
                                TextEntry::make('cost_price')->money('MVR'),
                                TextEntry::make('selling_price')->money('MVR'),
                                TextEntry::make('wholesale_price')->money('MVR'),
                                TextEntry::make('tax_category')
                                    ->label('GST Classification')
                                    ->formatStateUsing(fn (TaxCategory|string|null $state): string => $state instanceof TaxCategory ? $state->label() : (TaxCategory::tryFrom((string) $state)?->label() ?? '—')),
                                TextEntry::make('minimum_stock'),
                                IconEntry::make('track_inventory')->boolean(),
                                IconEntry::make('allow_negative_stock')->boolean(),
                                IconEntry::make('is_active')->boolean(),
                                IconEntry::make('show_online')->label('Shown Online')->boolean(),
                                IconEntry::make('is_featured')->label('Featured')->boolean(),
                                TextEntry::make('sale_price')->money('MVR'),
                                TextEntry::make('short_description')->columnSpanFull(),
                                TextEntry::make('description')->html()->columnSpanFull(),
                            ]),
                    ]),
                Section::make('Barcodes')
                    ->schema([
                        TextEntry::make('barcodes.barcode')
                            ->listWithLineBreaks()
                            ->label('Assigned Barcodes'),
                    ]),
                Section::make('Inventory')
                    ->schema([
                        View::make('filament.products.inventory-snapshot')
                            ->viewData(fn (?Product $record): array => static::inventoryViewData($record)),
                    ]),
            ]);
    }

    private static function inventoryViewData(?Product $record): array
    {
        if (! $record) {
            return [
                'trackInventory' => false,
                'rows' => [],
                'totalQuantity' => '0.0000',
                'inventoryOverviewUrl' => null,
            ];
        }

        $rows = app(InventoryQueryService::class)
            ->productInventorySnapshot($record->company_id, $record->id)
            ->map(function (array $row) use ($record): array {
                $quantity = (float) $row['current_quantity'];
                $status = InventoryStatus::forProduct((bool) $record->track_inventory, $quantity, (float) $record->minimum_stock);

                return [
                    ...$row,
                    'status' => $status,
                    'status_color' => InventoryStatus::color($status),
                    'inventory_url' => InventoryOverviewResource::getUrl('index', [
                        'search' => $record->sku,
                        'filters' => ['warehouse_id' => ['value' => $row['id']]],
                    ]),
                ];
            })
            ->all();

        return [
            'trackInventory' => (bool) $record->track_inventory,
            'rows' => $rows,
            'totalQuantity' => number_format(collect($rows)->sum(fn (array $row): float => (float) $row['current_quantity']), 4, '.', ''),
            'inventoryOverviewUrl' => InventoryOverviewResource::getUrl('index', ['search' => $record->sku]),
        ];
    }
}
