<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Actions\User\CreateUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates an approved company from the server, with its owner's account
 * when the email has none. Company requests (the register form and "ask
 * for a company") are otherwise the only way a company is made, so this
 * lets them stay off (SOCIALHQ_COMPANY_REQUESTS=false) where strangers
 * shouldn't sign up.
 */
class CreateCompanyCommand extends Command
{
    protected $signature = 'socialhq:company
        {email : The owner\'s email}
        {name : The company\'s name}
        {--owner-name= : The owner\'s name, for a new account (default: the email before the @)}
        {--platform-ai : Let the company use Social HQ\'s own AI keys}';

    protected $description = 'Create an approved company and, if needed, its owner\'s account';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = Str::squish((string) $this->argument('name'));
        $ownerName = Str::squish((string) $this->option('owner-name')) ?: Str::before($email, '@');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'owner_name' => $ownerName],
            ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:120'], 'owner_name' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $owner = User::query()->where('email', $email)->first();
        $isNewOwner = $owner === null;

        if ($owner !== null && ($refusal = $this->refusalFor($owner)) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if ($owner !== null && ($company = Company::forAccount($owner->account)) !== null) {
            if ($company->isApproved()) {
                $this->info("{$email} already owns the approved company {$company->name}. Nothing changed.");

                return self::SUCCESS;
            }

            $this->error("{$email}'s company {$company->name} is {$company->status->value}. Decide it on the platform's companies page.");

            return self::FAILURE;
        }

        // CreateUser's invite path: an account of their own, the email taken as
        // confirmed (whoever runs this vouches for it) and no workspace yet (the
        // owner creates the brands).
        $owner ??= CreateUser::execute(['name' => $ownerName, 'email' => $email, 'is_invite' => true, 'email_verified_at' => now()]);

        $company = CompanyDecisions::createApproved($owner, $name, (bool) $this->option('platform-ai'));

        if ($company === null) {
            $this->error("{$email}'s account became a company meanwhile. Nothing changed.");

            return self::FAILURE;
        }

        $this->info("{$company->name} is an approved company. Its owner, {$email}, can now create workspaces.");

        if ($isNewOwner) {
            $broker = Password::broker();
            $link = route('password.reset', ['token' => $broker->createToken($owner), 'email' => $owner->getEmailForPasswordReset()]);
            $passwords = (string) config('auth.defaults.passwords');
            $minutes = (int) config("auth.passwords.{$passwords}.expire", 60);

            $this->line("They set a password with this link (it works for {$minutes} minutes), or later with \"Forgot password\" on the login page:");
            $this->line($link);
        }

        $this->line("To make them a platform admin: php artisan socialhq:platform-admin {$email}");

        return self::SUCCESS;
    }

    /**
     * Why an existing user can't own a new company, if they can't.
     */
    private function refusalFor(User $owner): ?string
    {
        if (! $owner->isAccountOwner()) {
            return "{$owner->email} works in another company's account. Use another email for the owner.";
        }

        if (! $owner->hasVerifiedEmail()) {
            return "{$owner->email} has an account whose email isn't confirmed, so whoever made it may not own the address. They confirm it (or reset the password) first.";
        }

        return null;
    }
}
