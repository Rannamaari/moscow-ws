<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminSupport;
use App\Models\Branch;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

class StorefrontSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Storefront Settings';

    protected static ?string $title = 'Moscow Traders Wholesale Storefront';

    protected string $view = 'filament.pages.storefront-settings';

    public ?array $data = [];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.administration');
    }

    public function mount(): void
    {
        $company = AdminSupport::company();
        abort_unless($company, 403);

        $delivery = $company->website_delivery_methods ?? [];
        $payments = $company->website_payment_methods ?? [];
        $this->form->fill([
            ...$company->only(['website_enabled', 'online_branch_id', 'online_warehouse_id', 'phone', 'email', 'address', 'website_whatsapp']),
            'pickup_enabled' => array_key_exists('pickup', $delivery) || $delivery === [],
            'delivery_enabled' => array_key_exists('local_delivery', $delivery) || $delivery === [],
            'cash_enabled' => array_key_exists('cash', $payments) || $payments === [],
            'bank_transfer_enabled' => array_key_exists('bank_transfer', $payments) || $payments === [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Storefront Availability')->schema([
                Toggle::make('website_enabled')->label('Website enabled')->default(true),
                Grid::make(2)->schema([
                    Select::make('online_branch_id')->label('Online sales branch')->required()->options(fn (): array => Branch::query()->where('company_id', AdminSupport::companyId())->where('is_active', true)->pluck('name', 'id')->all()),
                    Select::make('online_warehouse_id')->label('Online stock warehouse')->required()->options(fn (): array => Warehouse::query()->where('company_id', AdminSupport::companyId())->where('is_active', true)->pluck('name', 'id')->all()),
                ]),
            ]),
            Section::make('Public Contact Details')->schema([
                Grid::make(2)->schema([
                    TextInput::make('phone')->tel()->maxLength(255),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('website_whatsapp')->label('WhatsApp number')->tel()->maxLength(255),
                    Textarea::make('address')->rows(2),
                ]),
            ]),
            Section::make('Checkout Options')->schema([
                Grid::make(2)->schema([
                    Toggle::make('pickup_enabled')->label('Store Pickup')->default(true),
                    Toggle::make('delivery_enabled')->label('Local Delivery')->default(true),
                    Toggle::make('cash_enabled')->label('Cash / Pay on Collection')->default(true),
                    Toggle::make('bank_transfer_enabled')->label('Bank Transfer')->default(true),
                ]),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $company = AdminSupport::company();
        abort_unless($company, 403);
        $state = $this->form->getState();

        abort_if(! $state['pickup_enabled'] && ! $state['delivery_enabled'], 422, 'Enable at least one fulfilment method.');
        abort_if(! $state['cash_enabled'] && ! $state['bank_transfer_enabled'], 422, 'Enable at least one payment method.');

        $company->update([
            'website_enabled' => $state['website_enabled'],
            'online_branch_id' => $state['online_branch_id'],
            'online_warehouse_id' => $state['online_warehouse_id'],
            'phone' => $state['phone'] ?: null,
            'email' => $state['email'] ?: null,
            'address' => $state['address'] ?: null,
            'website_whatsapp' => $state['website_whatsapp'] ?: null,
            'website_delivery_methods' => array_filter([
                'pickup' => $state['pickup_enabled'] ? 'Store Pickup' : null,
                'local_delivery' => $state['delivery_enabled'] ? 'Local Delivery' : null,
            ]),
            'website_payment_methods' => array_filter([
                'cash' => $state['cash_enabled'] ? 'Cash / Pay on Collection' : null,
                'bank_transfer' => $state['bank_transfer_enabled'] ? 'Bank Transfer' : null,
            ]),
        ]);

        Cache::forget('storefront.company');
        Notification::make()->title('Storefront settings saved')->success()->send();
    }
}
