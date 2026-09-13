<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'legal_name' => fake()->company().' LLC',
            'registration_number' => fake()->optional()->numerify('REG-#####'),
            'tax_number' => fake()->optional()->numerify('TAX-#####'),
            'default_tax_rate' => 0,
            'gst_filing_frequency' => 'quarterly',
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->companyEmail(),
            'address' => fake()->optional()->address(),
            'city' => fake()->optional()->city(),
            'country' => fake()->optional()->country(),
            'timezone' => 'Indian/Maldives',
            'currency' => 'MVR',
            'is_active' => true,
            'pos_test_mode' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
