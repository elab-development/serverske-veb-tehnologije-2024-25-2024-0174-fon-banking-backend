<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'jmbg' => fake()->unique()->numerify('#############'),
            'phone_number' => fake()->unique()->numerify('+3816########'),
            'email' => fake()->unique()->safeEmail(),
            'pin_hash' => '1234',
            'status' => 'active',
        ];
    }
}
