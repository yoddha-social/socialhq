<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Ai\Company;

use App\Models\Account;
use App\SocialHq\Ai\Company\CredentialStatus;
use App\SocialHq\Ai\Company\ProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    protected $model = ProviderCredential::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'provider' => 'groq',
            'litellm_credential_name' => fn (array $attributes): string => "shq-co-{$attributes['account_id']}-".($attributes['provider'] ?? 'groq'),
            'last4' => 'ab12',
            'status' => CredentialStatus::Ok,
            'last_tested_at' => now(),
            'position' => 1,
        ];
    }
}
