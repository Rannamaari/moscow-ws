<?php

namespace App\Enums;

enum TaxCategory: string
{
    case StandardRated = 'standard_rated';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::StandardRated => 'Standard-rated',
            self::ZeroRated => 'Zero-rated',
            self::Exempt => 'Exempt',
            self::OutOfScope => 'Out of scope',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])
            ->all();
    }
}
