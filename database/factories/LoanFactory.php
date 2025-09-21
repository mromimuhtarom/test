<?php

namespace Database\Factories;

use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;

class LoanFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Loan::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'terms' => 3,
            'amount' => 5000,
            'outstanding_amount' => 5000, // 👈 default sama dengan amount
            'currency_code' => Loan::CURRENCY_VND,
            'processed_at' => now(),
            'status' => Loan::STATUS_DUE,
        ];
    }
}
