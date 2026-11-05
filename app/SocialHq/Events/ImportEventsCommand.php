<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Loads the official dates into an account, unconfirmed. Safe to run again:
 * confirmations and rows a person edited are kept.
 */
class ImportEventsCommand extends Command
{
    protected $signature = 'socialhq:events:import
        {--account= : The account id (needed when there is more than one account)}
        {--file=* : A CSV file to read instead of the shipped dataset (repeatable)}';

    protected $description = 'Import the official Nepali and international dates into an account, unconfirmed';

    public function handle(): int
    {
        $account = $this->account();

        if ($account === null) {
            return self::FAILURE;
        }

        $files = collect((array) $this->option('file'))
            ->filter()
            ->map(fn (string $file): string => is_file($file) ? $file : database_path(ImportOfficialEvents::DIRECTORY."/{$file}"))
            ->values()
            ->all();

        try {
            $counts = ImportOfficialEvents::handle($account, $files === [] ? null : $files);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created {$counts['created']}, updated {$counts['updated']}, skipped {$counts['skipped']} (unchanged or edited in the app).");

        return self::SUCCESS;
    }

    private function account(): ?Account
    {
        $id = $this->option('account');

        if (filled($id)) {
            $account = Account::query()->find($id);

            if ($account === null) {
                $this->error("No account with id {$id}.");
            }

            return $account;
        }

        $accounts = Account::query()->limit(2)->get();

        if ($accounts->count() === 1) {
            return $accounts->first();
        }

        $this->error($accounts->isEmpty() ? 'There is no account yet.' : 'There is more than one account: pass --account=<id>.');

        return null;
    }
}
