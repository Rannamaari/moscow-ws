<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductCatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('name', 'Moscow Traders Wholesale')->firstOrFail();

        $categories = collect([
            ['name' => 'Beverages', 'code' => 'BEV'],
            ['name' => 'Food Staples', 'code' => 'STAPLES'],
            ['name' => 'Sauces & Condiments', 'code' => 'SAUCES'],
            ['name' => 'Tea & Coffee', 'code' => 'TEACOFFEE'],
            ['name' => 'Dairy & Eggs', 'code' => 'DAIRY'],
            ['name' => 'Canned Food', 'code' => 'CANNED'],
        ])->mapWithKeys(function (array $category) use ($company) {
            $record = Category::query()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $category['name']],
                ['code' => $category['code'], 'slug' => Str::slug($category['name']), 'is_active' => true]
            );

            return [$category['name'] => $record];
        });

        $brandNames = ['XL', 'Coca-Cola', 'Fanta', 'Sprite', 'Red Bull', 'Tiger', 'Mamee', 'Speed', 'Pepsi', 'Yeye', 'Nescafe', 'Safa', 'Akbar', 'Lacnor', 'Sunquick', 'Generic'];
        $brands = collect($brandNames)->mapWithKeys(function (string $name) use ($company) {
            $record = Brand::query()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 8)), 'is_active' => true]
            );

            return [$name => $record];
        });

        $units = Unit::query()->whereIn('short_name', ['case', 'bag', 'jar'])->get()->keyBy('short_name');

        $products = [
            ['XL-BIG-250', 'XL Energy Drink Big 250ml', 'Beverages', 'XL', 'case', 600, 'Case of 24 cans'],
            ['XL-SMALL-150', 'XL Energy Drink Small 150ml', 'Beverages', 'XL', 'case', 420, 'Case of 24 cans'],
            ['COKE-CAN-330', 'Coca-Cola Can 330ml', 'Beverages', 'Coca-Cola', 'case', 295, 'Case of 24 cans'],
            ['FANTA-ORANGE-CAN-330', 'Fanta Orange Can 330ml', 'Beverages', 'Fanta', 'case', 295, 'Case of 24 cans'],
            ['FANTA-STRAWBERRY-CAN-330', 'Fanta Strawberry Can 330ml', 'Beverages', 'Fanta', 'case', 295, 'Case of 24 cans'],
            ['SPRITE-CAN-330', 'Sprite Can 330ml', 'Beverages', 'Sprite', 'case', 295, 'Case of 24 cans'],
            ['COKE-BOTTLE-500', 'Coca-Cola Bottle 500ml', 'Beverages', 'Coca-Cola', 'case', 300, 'Case of 24 bottles'],
            ['FANTA-ORANGE-BOTTLE-500', 'Fanta Orange Bottle 500ml', 'Beverages', 'Fanta', 'case', 300, 'Case of 24 bottles'],
            ['FANTA-STRAWBERRY-BOTTLE-500', 'Fanta Strawberry Bottle 500ml', 'Beverages', 'Fanta', 'case', 300, 'Case of 24 bottles'],
            ['SPRITE-BOTTLE-500', 'Sprite Bottle 500ml', 'Beverages', 'Sprite', 'case', 300, 'Case of 24 bottles'],
            ['REDBULL-CAN-250', 'Red Bull Can 250ml', 'Beverages', 'Red Bull', 'case', 895, 'Case of 24 cans'],
            ['SODA-WATER-CAN-320', 'Soda Water Can 320ml', 'Beverages', 'Generic', 'case', 290, 'Case of 24 cans'],
            ['BITTER-LEMON-CAN-330', 'Bitter Lemon Can 330ml', 'Beverages', 'Generic', 'case', 290, 'Case of 24 cans'],
            ['TIGER-CAN-250', 'Tiger Can 250ml', 'Beverages', 'Tiger', 'case', 310, 'Case of 24 cans'],
            ['MAMEE-NOODLES', 'Mamee Noodles', 'Food Staples', 'Mamee', 'case', 180, 'Case of 30 packets'],
            ['SPEED-CAN-250', 'Speed Can 250ml', 'Beverages', 'Speed', 'case', 300, 'Case of 24 cans'],
            ['COKE-MINI-CAN-185', 'Coca-Cola Mini Can 185ml', 'Beverages', 'Coca-Cola', 'case', 205, 'Case of 28 cans'],
            ['SPRITE-MINI-CAN-185', 'Sprite Mini Can 185ml', 'Beverages', 'Sprite', 'case', 205, 'Case of 28 cans'],
            ['PEPSI-MINI-CAN-185', 'Pepsi Mini Can 185ml', 'Beverages', 'Pepsi', 'case', 205, 'Case of 28 cans'],
            ['TUNA-IN-OIL', 'Tuna in Oil', 'Canned Food', 'Generic', 'case', 785, 'Case of 48 cans'],
            ['EGG-CASE', 'Egg Case', 'Dairy & Eggs', 'Generic', 'case', 480, null],
            ['YEYE-COFFEE-CASE', 'Yeye Coffee Case', 'Tea & Coffee', 'Yeye', 'case', 2205, null],
            ['NESCAFE-TIN-500-CASE', 'Nescafe Tin 500g Case', 'Tea & Coffee', 'Nescafe', 'case', 3080, null],
            ['SALT-PACKET-CASE', 'Salt Packet Case', 'Food Staples', 'Generic', 'case', 240, null],
            ['SAFA-TOMATO-SAUCE-CASE', 'Safa Tomato Sauce Case', 'Sauces & Condiments', 'Safa', 'case', 305, null],
            ['SAFA-CHILLI-SAUCE-CASE', 'Safa Chilli Sauce Case', 'Sauces & Condiments', 'Safa', 'case', 325, null],
            ['SAFA-TOMATO-PASTE-400-CASE', 'Safa Tomato Paste 400g Case', 'Sauces & Condiments', 'Safa', 'case', 390, null],
            ['AKBAR-TEA-BAG-CASE', 'Akbar Tea Bag Case', 'Tea & Coffee', 'Akbar', 'case', 465, null],
            ['RICE-50KG-BAG', 'Rice 50kg Bag', 'Food Staples', 'Generic', 'bag', 240, null],
            ['SUGAR-50KG-BAG', 'Sugar 50kg Bag', 'Food Staples', 'Generic', 'bag', 240, null],
            ['FLOUR-50KG-BAG', 'Flour 50kg Bag', 'Food Staples', 'Generic', 'bag', 190, null],
            ['WATER-1500-CASE', 'Water 1.5L Case', 'Beverages', 'Generic', 'case', 67, null],
            ['WATER-500-CASE', 'Water 500ml Case', 'Beverages', 'Generic', 'case', 67, null],
            ['WATER-5L-CASE', 'Water 5L Case', 'Beverages', 'Generic', 'case', 67, null],
            ['COOKING-OIL-18L-JAR', 'Cooking Oil Jar 18L', 'Food Staples', 'Generic', 'jar', 475, null],
            ['LACNOR-FULLCREAM-1L-CASE', 'Lacnor Full Cream 1L Case', 'Dairy & Eggs', 'Lacnor', 'case', 455, null],
            ['SUNQUICK-CASE', 'Sunquick Case', 'Beverages', 'Sunquick', 'case', 485, null],
        ];

        foreach ($products as $index => [$sku, $name, $category, $brand, $unit, $price, $description]) {
            Product::query()->updateOrCreate(
                ['company_id' => $company->id, 'sku' => $sku],
                [
                    'category_id' => $categories[$category]->id,
                    'brand_id' => $brands[$brand]->id,
                    'unit_id' => $units[$unit]->id,
                    'name' => $name,
                    'slug' => Str::slug($name.'-'.$sku),
                    'description' => $description,
                    'short_description' => $description,
                    'cost_price' => 0,
                    'selling_price' => $price,
                    'wholesale_price' => $price,
                    'tax_rate' => 0,
                    'is_taxable' => true,
                    'minimum_stock' => 0,
                    'allow_negative_stock' => false,
                    'track_inventory' => true,
                    'is_active' => true,
                    'show_online' => true,
                    'is_featured' => $index < 8,
                ]
            );
        }
    }
}
