<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Filament\Support\AdminSupport;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('company_id')->default(fn (): ?string => AdminSupport::companyId()),
                Section::make('Customer Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('code')->required()->maxLength(255),
                                TextInput::make('name')->required()->maxLength(255),
                                TextInput::make('phone')->maxLength(255),
                                TextInput::make('email')->email()->maxLength(255),
                                TextInput::make('registration_number')->maxLength(255),
                                TextInput::make('tax_number')->label('GST Registration Number')->maxLength(255),
                                TextInput::make('city')->maxLength(255),
                                TextInput::make('credit_limit')->numeric()->minValue(0)->default(0),
                                Select::make('payment_terms_days')
                                    ->label('Credit Terms')
                                    ->options([7 => 'Due 7', 15 => 'Due 15', 30 => 'Due 30'])
                                    ->default(7)
                                    ->required()
                                    ->native(false),
                                TextInput::make('opening_balance')->numeric()->default(0),
                                Toggle::make('is_walk_in')->inline(false),
                                Toggle::make('is_active')->default(true)->inline(false),
                                Textarea::make('address')->rows(3)->columnSpanFull(),
                                Textarea::make('notes')->rows(4)->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
