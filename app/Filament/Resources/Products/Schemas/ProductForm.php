<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\TaxCategory;
use App\Filament\Resources\InventoryOverview\InventoryOverviewResource;
use App\Filament\Support\AdminSupport;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryQueryService;
use App\Services\ProductImageOptimizer;
use App\Support\InventoryStatus;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('company_id')
                    ->default(fn (): ?string => AdminSupport::companyId()),
                Section::make('Last Product Added')
                    ->description('Reference only. This is the latest product added to your company catalog.')
                    ->visibleOn('create')
                    ->schema([
                        Placeholder::make('last_product_added')
                            ->label('Product / SKU')
                            ->content(function (): string {
                                $product = Product::query()
                                    ->where('company_id', AdminSupport::companyId())
                                    ->latest('created_at')
                                    ->first(['name', 'sku']);

                                return $product
                                    ? "{$product->name} (SKU: {$product->sku})"
                                    : 'No products have been added yet.';
                            }),
                    ]),
                Section::make('Product Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('sku')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(
                                        table: 'products',
                                        column: 'sku',
                                        ignoreRecord: true,
                                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', AdminSupport::companyId()),
                                    ),
                                Select::make('category_id')
                                    ->options(fn (): array => AdminSupport::categoryOptions())
                                    ->searchable()
                                    ->preload(),
                                Select::make('brand_id')
                                    ->options(fn (): array => AdminSupport::brandOptions())
                                    ->searchable()
                                    ->preload(),
                                Select::make('unit_id')
                                    ->required()
                                    ->options(fn (): array => AdminSupport::unitOptions())
                                    ->searchable()
                                    ->preload(),
                                Toggle::make('is_active')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
                Section::make('Online Store')
                    ->description('Control how this product appears on the Moscow Traders Wholesale website.')
                    ->schema([
                        Grid::make(3)->schema([
                            Toggle::make('show_online')->label('Show Online')->default(false)->inline(false),
                            Toggle::make('is_featured')->label('Featured Product')->default(false)->inline(false),
                            TextInput::make('sale_price')
                                ->label('Online Sale Price')
                                ->numeric()
                                ->minValue(0)
                                ->helperText('Enter the price before GST. The website displays the GST-inclusive total.'),
                        ]),
                        Textarea::make('short_description')->rows(2)->maxLength(500)->columnSpanFull(),
                        RichEditor::make('description')
                            ->label('Product Description')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3'],
                                ['blockquote', 'bulletList', 'orderedList'],
                                ['horizontalRule', 'undo', 'redo'],
                            ])
                            ->columnSpanFull(),
                        FileUpload::make('images')
                            ->label('Product Images')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->multiple()
                            ->reorderable()
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['1:1'])
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(ProductImageOptimizer::class)->store($file))
                            ->maxSize(8192)
                            ->rule(Rule::dimensions()->maxWidth(6000)->maxHeight(6000))
                            ->maxFiles(8)
                            ->helperText('JPG, PNG or WebP. Images are automatically fitted onto a square up to 1200 × 1200 and compressed as WebP. Maximum upload: 8 MB each.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Pricing')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('cost_price')->numeric()->minValue(0)->default(0),
                                TextInput::make('selling_price')->numeric()->minValue(0)->required(),
                                TextInput::make('wholesale_price')->numeric()->minValue(0),
                                Select::make('tax_category')
                                    ->label('GST Classification')
                                    ->options(TaxCategory::options())
                                    ->default(TaxCategory::StandardRated->value)
                                    ->helperText('Standard-rated products use the GST rate in Settings. Select the exact MIRA category for all others.')
                                    ->required(),
                                TextInput::make('minimum_stock')->numeric()->minValue(0)->default(0),
                            ]),
                    ]),
                Section::make('Store Prices')
                    ->description('Set the selling and cost price for each store. POS always uses the price for its assigned store.')
                    ->schema([
                        Repeater::make('branchPrices')
                            ->relationship()
                            ->reorderable(false)
                            ->schema([
                                Select::make('branch_id')
                                    ->required()
                                    ->options(fn (): array => Branch::query()->where('company_id', AdminSupport::companyId())->orderBy('name')->pluck('name', 'id')->all()),
                                Select::make('currency')->required()->options(['MVR' => 'MVR', 'USD' => 'USD']),
                                TextInput::make('cost_price')->numeric()->minValue(0)->default(0)->required(),
                                TextInput::make('selling_price')->numeric()->minValue(0)->required(),
                                TextInput::make('wholesale_price')->numeric()->minValue(0),
                                Hidden::make('company_id')->default(fn (): ?string => AdminSupport::companyId()),
                            ])->columns(5),
                    ]),
                Section::make('Inventory Rules')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('track_inventory')
                                    ->default(true)
                                    ->inline(false),
                                Toggle::make('allow_negative_stock')
                                    ->default(false)
                                    ->inline(false),
                            ]),
                    ]),
                Section::make('Opening Stock')
                    ->description('Optional. Records the product quantity currently on hand in the selected warehouse.')
                    ->visibleOn('create')
                    ->visible(fn (Get $get): bool => (bool) $get('track_inventory'))
                    ->dehydratedWhenHidden(false)
                    ->schema([
                        Select::make('opening_warehouse_id')
                            ->label('Warehouse')
                            ->options(fn (): array => Warehouse::query()
                                ->where('company_id', AdminSupport::companyId())
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->default(fn (): ?string => AdminSupport::activeWarehouseId())
                            ->searchable()
                            ->required(fn (Get $get): bool => (float) $get('opening_quantity') > 0),
                        TextInput::make('opening_quantity')
                            ->label('Opening Quantity')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Leave as 0 when there is no stock to record.'),
                        TextInput::make('opening_unit_cost')
                            ->label('Opening Unit Cost')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Optional. Uses the product cost price when left blank.'),
                    ])
                    ->columns(3),
                Section::make('Barcodes')
                    ->description('Use one primary barcode for scanner lookup and keep any alternate barcodes on the same product.')
                    ->schema([
                        Repeater::make('barcodes')
                            ->relationship()
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('barcode')
                                    ->required()
                                    ->maxLength(255),
                                Toggle::make('is_primary')
                                    ->label('Primary')
                                    ->default(false)
                                    ->inline(false),
                                Hidden::make('company_id')
                                    ->default(fn (): ?string => AdminSupport::companyId()),
                            ])
                            ->columns(2),
                    ]),
                Section::make('Inventory')
                    ->visible(fn (?Product $record): bool => (bool) $record?->exists)
                    ->schema([
                        ViewField::make('inventory_snapshot')
                            ->view('filament.products.inventory-snapshot')
                            ->dehydrated(false)
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

                return [
                    ...$row,
                    'status' => InventoryStatus::forProduct((bool) $record->track_inventory, $quantity, (float) $record->minimum_stock),
                    'status_color' => InventoryStatus::color(InventoryStatus::forProduct((bool) $record->track_inventory, $quantity, (float) $record->minimum_stock)),
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
