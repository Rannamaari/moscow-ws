<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CUS-####')),
            'name' => fake()->name(),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'registration_number' => fake()->optional()->bothify('REG-####'),
            'tax_number' => fake()->optional()->bothify('TAX-####'),
            'address' => fake()->optional()->address(),
            'city' => fake()->optional()->city(),
            'credit_limit' => fake()->optional()->randomFloat(4, 500, 5000),
            'payment_terms_days' => 7,
            'opening_balance' => 0,
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
            'is_walk_in' => false,
        ];
    }

    public function walkIn(): static
    {
        return $this->state(fn () => [
            'code' => 'WALK-IN',
            'name' => 'Walk-in Customer',
            'credit_limit' => null,
            'is_walk_in' => true,
        ]);
    }
}
