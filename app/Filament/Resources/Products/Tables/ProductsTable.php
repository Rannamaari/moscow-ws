<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\TaxCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $nested) use ($search): void {
                            $nested->whereLike('products.name', "%{$search}%", caseSensitive: false)
                                ->orWhereLike('products.sku', "%{$search}%", caseSensitive: false)
                                ->orWhereHas('barcodes', fn (Builder $barcodeQuery): Builder => $barcodeQuery->whereLike('barcode', "%{$search}%", caseSensitive: false));
                        });
                    })
                    ->sortable(),
                TextColumn::make('primaryBarcode.barcode')
                    ->label('Primary Barcode')
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->toggleable(),
                TextColumn::make('brand.name')
                    ->toggleable(),
                TextColumn::make('unit.short_name')
                    ->label('Unit'),
                TextColumn::make('cost_price')
                    ->money('MVR')
                    ->sortable(),
                TextColumn::make('selling_price')
                    ->money('MVR')
                    ->sortable(),
                TextColumn::make('minimum_stock')
                    ->sortable(),
                TextColumn::make('tax_category')
                    ->label('GST')
                    ->formatStateUsing(fn (TaxCategory|string|null $state): string => $state instanceof TaxCategory ? $state->label() : (TaxCategory::tryFrom((string) $state)?->label() ?? '—')),
                IconColumn::make('track_inventory')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('show_online')
                    ->label('Online')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->relationship('category', 'name'),
                SelectFilter::make('brand_id')
                    ->relationship('brand', 'name'),
                SelectFilter::make('track_inventory')
                    ->options([
                        1 => 'Tracked',
                        0 => 'Not Tracked',
                    ]),
                SelectFilter::make('is_active')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ]),
                SelectFilter::make('show_online')
                    ->label('Online visibility')
                    ->options([1 => 'Shown Online', 0 => 'Hidden Online']),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }
}
