<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

final class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'emails' => [fake()->unique()->safeEmail()],
            'phone' => fake()->phoneNumber(),
            'phone_secondary' => fake()->optional(0.3)->phoneNumber(),
            'position' => fake()->optional(0.7)->jobTitle(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'region' => fake()->state(),
            'country' => 'US',
            'postal_code' => fake()->postcode(),
            'notes' => fake()->optional(0.2)->sentence(),
        ];
    }
}
