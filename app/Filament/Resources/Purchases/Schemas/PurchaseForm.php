<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseStatus;
use App\Enums\TaxCategory;
use App\Filament\Support\AdminSupport;
use App\Models\Product;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Purchase Order')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 4,
                        ])
                            ->schema([
                                Select::make('supplier_id')
                                    ->label('Supplier')
                                    ->required()
                                    ->options(fn (): array => AdminSupport::supplierOptions())
                                    ->default(request()->query('supplier_id'))
                                    ->searchable()
                                    ->preload(),
                                Select::make('warehouse_id')
                                    ->label('Warehouse')
                                    ->required()
                                    ->options(fn (): array => AdminSupport::warehouseOptions())
                                    ->default(fn (): ?string => AdminSupport::resolveAuthorizedWarehouseId())
                                    ->searchable()
                                    ->disabled(fn (): bool => count(AdminSupport::warehouseOptions()) === 1)
                                    ->dehydrated(),
                                Select::make('status')
                                    ->label('Save Action')
                                    ->required()
                                    ->default(PurchaseStatus::Ordered->value)
                                    ->options(function (): array {
                                        $options = [
                                            PurchaseStatus::Draft->value => 'Save Draft',
                                            PurchaseStatus::Ordered->value => 'Create Order (stock unchanged)',
                                        ];

                                        if (auth()->user()?->can('purchases.receive')) {
                                            $options['receive_now'] = 'Save & Receive into Inventory';
                                        }

                                        return $options;
                                    })
                                    ->helperText('Choose Save & Receive when the supplier items have already arrived.'),
                                DatePicker::make('purchase_date')
                                    ->required()
                                    ->default(now()),
                                DatePicker::make('expected_date'),
                                TextInput::make('supplier_invoice_number')
                                    ->label('Supplier Invoice Number')
                                    ->maxLength(255),
                                TextInput::make('shipping_total')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(debounce: 300),
                                TextInput::make('other_cost_total')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(debounce: 300),
                                Textarea::make('notes')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Section::make('Products')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->label('Purchase Lines')
                            ->required()
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Add Product')
                            ->reorderable(false)
                            ->schema([
                                Hidden::make('description'),
                                Grid::make([
                                    'default' => 1,
                                    'md' => 6,
                                    'xl' => 12,
                                ])
                                    ->schema([
                                        Select::make('product_id')
                                            ->label('Product')
                                            ->required()
                                            ->searchable()
                                            ->live()
                                            ->getSearchResultsUsing(fn (string $search): array => static::searchProducts($search))
                                            ->getOptionLabelUsing(fn ($value): ?string => static::productLabel($value))
                                            ->afterStateUpdated(function ($state, $set): void {
                                                $product = $state ? Product::query()->with('primaryBarcode')->find($state) : null;

                                                $set('description', $product?->name);
                                                $set('unit_cost', $product ? (float) $product->cost_price : 0);
                                                $set('tax_rate', $product ? $product->effectiveTaxRate() : 0);
                                                $set('tax_category', $product?->tax_category?->value ?? TaxCategory::StandardRated->value);
                                                $set('input_tax_claimable', (bool) $product?->is_taxable);
                                            })
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 6,
                                                'xl' => 6,
                                            ]),
                                        TextInput::make('ordered_quantity')
                                            ->label('Quantity')
                                            ->required()
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(0.0001)
                                            ->live(debounce: 300)
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 2,
                                                'xl' => 2,
                                            ]),
                                        TextInput::make('unit_cost')
                                            ->label('Cost per Unit')
                                            ->required()
                                            ->numeric()
                                            ->default(0)
                                            ->minValue(0)
                                            ->live(debounce: 300)
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 2,
                                                'xl' => 2,
                                            ]),
                                        Placeholder::make('line_total_preview')
                                            ->label('Line Total')
                                            ->content(fn ($get): HtmlString => new HtmlString('<strong style="font-size:1.1rem">'.number_format(static::lineTotal($get), 2, '.', ',').'</strong>'))
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 2,
                                                'xl' => 2,
                                            ]),
                                        TextInput::make('discount_amount')
                                            ->label('Line Discount')
                                            ->numeric()
                                            ->default(0)
                                            ->minValue(0)
                                            ->live(debounce: 300)
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 3,
                                                'xl' => 2,
                                            ]),
                                        TextInput::make('tax_rate')
                                            ->label('Tax %')
                                            ->numeric()
                                            ->default(0)
                                            ->minValue(0)
                                            ->live(debounce: 300)
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 3,
                                                'xl' => 2,
                                            ]),
                                        Select::make('tax_category')
                                            ->label('GST Classification')
                                            ->options(TaxCategory::options())
                                            ->default(TaxCategory::StandardRated->value)
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function ($state, $set): void {
                                                if ($state !== TaxCategory::StandardRated->value) {
                                                    $set('tax_rate', 0);
                                                    $set('input_tax_claimable', false);
                                                }
                                            })
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 6,
                                                'xl' => 4,
                                            ]),
                                        Toggle::make('price_includes_tax')
                                            ->label('Cost includes GST')
                                            ->helperText('Enable when the entered unit cost already includes GST.')
                                            ->default(false)
                                            ->live()
                                            ->inline(false)
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 3,
                                                'xl' => 2,
                                            ]),
                                        Toggle::make('input_tax_claimable')
                                            ->label('Claim input GST')
                                            ->helperText('Disable when this GST cannot be claimed from MIRA.')
                                            ->default(true)
                                            ->live()
                                            ->inline(false)
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 3,
                                                'xl' => 2,
                                            ]),
                                    ]),
                            ])
                            ->columns(1),
                    ]),
                Section::make('Totals Preview')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'xl' => 5,
                        ])
                            ->schema([
                                Placeholder::make('subtotal_preview')
                                    ->label('Subtotal')
                                    ->content(fn ($get): string => number_format(static::totals($get)['subtotal'], 4, '.', '')),
                                Placeholder::make('discount_preview')
                                    ->label('Discount')
                                    ->content(fn ($get): string => number_format(static::totals($get)['discount_total'], 4, '.', '')),
                                Placeholder::make('tax_preview')
                                    ->label('Total GST')
                                    ->content(fn ($get): string => number_format(static::totals($get)['tax_total'], 4, '.', '')),
                                Placeholder::make('other_preview')
                                    ->label('Shipping + Other')
                                    ->content(fn ($get): string => number_format(static::totals($get)['other_total'], 4, '.', '')),
                                Placeholder::make('grand_total_preview')
                                    ->label('Grand Total')
                                    ->content(fn ($get): string => new HtmlString('<strong>'.number_format(static::totals($get)['grand_total'], 4, '.', '').'</strong>')),
                            ]),
                    ]),
            ]);
    }

    private static function searchProducts(string $search): array
    {
        $term = trim($search);

        if ($term === '' || ! AdminSupport::companyId()) {
            return [];
        }

        return Product::query()
            ->with('primaryBarcode')
            ->where('company_id', AdminSupport::companyId())
            ->where('is_active', true)
            ->where(function ($query) use ($term): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->whereLike('name', $like, caseSensitive: false)
                    ->orWhereLike('sku', $like, caseSensitive: false)
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery->whereLike('barcode', $like, caseSensitive: false));
            })
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->id => sprintf('%s (%s%s)', $product->name, $product->sku, $product->primaryBarcode?->barcode ? ' • '.$product->primaryBarcode->barcode : ''),
            ])
            ->all();
    }

    private static function productLabel(?string $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        $product = Product::query()->with('primaryBarcode')->find($productId);

        if (! $product) {
            return null;
        }

        return sprintf('%s (%s%s)', $product->name, $product->sku, $product->primaryBarcode?->barcode ? ' • '.$product->primaryBarcode->barcode : '');
    }

    private static function lineTotal($get): float
    {
        $quantity = (float) ($get('ordered_quantity') ?: 0);
        $unitCost = (float) ($get('unit_cost') ?: 0);
        $discount = (float) ($get('discount_amount') ?: 0);
        $taxRate = (float) ($get('tax_rate') ?: 0);
        $includesTax = (bool) $get('price_includes_tax');
        $subtotal = $quantity * $unitCost;
        $taxBase = max(0, $subtotal - $discount);
        $tax = $includesTax && $taxRate > 0
            ? $taxBase * ($taxRate / (100 + $taxRate))
            : $taxBase * ($taxRate / 100);

        return round($includesTax ? $taxBase : $taxBase + $tax, 4);
    }

    /**
     * @return array{subtotal:float,discount_total:float,tax_total:float,other_total:float,grand_total:float}
     */
    private static function totals($get): array
    {
        $items = $get('items') ?? [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item['ordered_quantity'] ?? 0);
            $unitCost = (float) ($item['unit_cost'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            $includesTax = (bool) ($item['price_includes_tax'] ?? false);
            $lineSubtotal = round($quantity * $unitCost, 4);
            $lineTaxBase = max(0, $lineSubtotal - $discount);
            $lineTax = round($includesTax && $taxRate > 0
                ? $lineTaxBase * ($taxRate / (100 + $taxRate))
                : $lineTaxBase * ($taxRate / 100), 4);

            $subtotal += $lineSubtotal;
            $discountTotal += $discount;
            $taxTotal += $lineTax;
        }

        $other = (float) ($get('shipping_total') ?: 0) + (float) ($get('other_cost_total') ?: 0);

        return [
            'subtotal' => round($subtotal, 4),
            'discount_total' => round($discountTotal, 4),
            'tax_total' => round($taxTotal, 4),
            'other_total' => round($other, 4),
            'grand_total' => round($subtotal - $discountTotal + collect($items)->reject(fn (array $item): bool => (bool) ($item['price_includes_tax'] ?? false))->sum(function (array $item): float {
                $base = max(0, ((float) ($item['ordered_quantity'] ?? 0) * (float) ($item['unit_cost'] ?? 0)) - (float) ($item['discount_amount'] ?? 0));
                $rate = (float) ($item['tax_rate'] ?? 0);

                return round($base * ($rate / 100), 4);
            }) + $other, 4),
        ];
    }
}
