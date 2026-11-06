<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Companies;

use App\Models\Account;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->company(),
            'status' => CompanyStatus::Approved,
            'uses_platform_ai' => false,
            'decided_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => CompanyStatus::Pending, 'decided_at' => null]);
    }

    public function usingPlatformAi(): static
    {
        return $this->state(fn (): array => ['uses_platform_ai' => true]);
    }
}
