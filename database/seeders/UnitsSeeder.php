<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitsSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $units = [
            ['name' => 'Piece', 'short_name' => 'pcs', 'precision' => 0],
            ['name' => 'Box', 'short_name' => 'box', 'precision' => 0],
            ['name' => 'Bottle', 'short_name' => 'btl', 'precision' => 0],
            ['name' => 'Pack', 'short_name' => 'pack', 'precision' => 0],
            ['name' => 'Kilogram', 'short_name' => 'kg', 'precision' => 3],
            ['name' => 'Gram', 'short_name' => 'g', 'precision' => 3],
            ['name' => 'Liter', 'short_name' => 'L', 'precision' => 3],
            ['name' => 'Milliliter', 'short_name' => 'ml', 'precision' => 3],
            ['name' => 'Meter', 'short_name' => 'm', 'precision' => 3],
            ['name' => 'Case', 'short_name' => 'case', 'precision' => 0],
            ['name' => 'Bag', 'short_name' => 'bag', 'precision' => 0],
            ['name' => 'Jar', 'short_name' => 'jar', 'precision' => 0],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(
                ['short_name' => $unit['short_name']],
                [
                    'name' => $unit['name'],
                    'precision' => $unit['precision'],
                    'is_active' => true,
                ]
            );
        }
    }
}
