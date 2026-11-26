<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyAccess;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Social HQ: whether a new account gets an approved company (see configure()).
     */
    private static bool $withCompany = true;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * Social HQ: under SOCIALHQ_TEST_ACCOUNTS_APPROVED (set only by the
     * production-rules job of socialhq-ci.yml), or while the test requires
     * companies (our own tests, tests/Pest.php), every account a test creates
     * gets an approved company, as the backfill gave every real account.
     * The suites then run with companies required, as production does,
     * instead of meeting a company-less account no real install has.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Account $account): void {
            $approved = filter_var(env('SOCIALHQ_TEST_ACCOUNTS_APPROVED', false), FILTER_VALIDATE_BOOL)
                || CompanyAccess::companiesRequired();

            if (! self::$withCompany || ! $approved) {
                return;
            }

            if (! Company::query()->where('account_id', $account->id)->exists()) {
                Company::factory()->create(['account_id' => $account->id, 'name' => $account->name]);
            }
        });
    }

    /**
     * Social HQ: runs the callback without the approved company configure()
     * adds, for factories that create the account's company themselves.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function withoutCompany(Closure $callback): mixed
    {
        $previous = self::$withCompany;
        self::$withCompany = false;

        try {
            return $callback();
        } finally {
            self::$withCompany = $previous;
        }
    }
}
