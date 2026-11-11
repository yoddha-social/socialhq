<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Grants or revokes platform admin: the only way to make one, so nobody
 * becomes one from inside the app.
 */
class PlatformAdminCommand extends Command
{
    protected $signature = 'socialhq:platform-admin {email : The user\'s email} {--revoke : Take platform admin away}';

    protected $description = 'Make a user a platform admin of this Social HQ server (or revoke it)';

    public function handle(): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No user has that email. They sign up first.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            PlatformAdmins::revoke($user);
            $this->info("{$user->email} is no longer a platform admin.");

            return self::SUCCESS;
        }

        PlatformAdmins::grant($user);
        $this->info("{$user->email} is now a platform admin.");

        return self::SUCCESS;
    }
}
