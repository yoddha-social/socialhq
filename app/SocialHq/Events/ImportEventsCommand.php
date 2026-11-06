<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use App\SocialHq\Events\Nager\NagerDateUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loads the official dates into an account, unconfirmed, or with --country
 * those countries' public holidays from Nager.Date. Safe to run again:
 * confirmations and rows a person edited are kept.
 */
class ImportEventsCommand extends Command
{
    protected $signature = 'socialhq:events:import
        {--account= : The account id (needed when there is more than one account)}
        {--file=* : A CSV file to read instead of the shipped dataset (repeatable)}
        {--country=* : Import this country\'s public holidays from Nager.Date instead (ISO code, repeatable; not NP)}
        {--year=* : With --country, the year to import (repeatable; this year and the next by default)}';

    protected $description = 'Import the official Nepali and international dates, or other countries\' public holidays, into an account, unconfirmed';

    public function handle(): int
    {
        $account = $this->account();

        if ($account === null) {
            return self::FAILURE;
        }

        $countries = collect((array) $this->option('country'))->filter()->map(fn (string $code): string => strtoupper(trim($code)))->values()->all();

        if ($countries !== []) {
            return $this->importCountries($account, $countries);
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

        $this->info("Created {$counts['created']}, updated {$counts['updated']}, skipped {$counts['skipped']} (unchanged, or edited or deleted in the app).");

        if ($counts['missing'] > 0) {
            $this->warn("{$counts['missing']} imported dates are no longer in the official list; they were left as they are.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $countries
     */
    private function importCountries(Account $account, array $countries): int
    {
        $years = collect((array) $this->option('year'))->filter()->map(fn (string $year): int => (int) $year)->values()->all();

        if ($years === []) {
            $years = [now()->year, now()->year + 1];
        }

        try {
            $counts = ImportPublicHolidays::handle($account, $countries, $years);
        } catch (InvalidArgumentException|NagerDateUnavailable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Public holidays of '.implode(', ', $countries).' for '.implode(', ', $years).": created {$counts['created']}, updated {$counts['updated']}, skipped {$counts['skipped']} (unchanged, or edited or deleted in the app).");

        if ($counts['missing'] > 0) {
            $this->warn("{$counts['missing']} imported holidays are no longer listed by Nager.Date; they were left as they are.");
        }

        return self::SUCCESS;
    }

    private function account(): ?Account
    {
        $id = $this->option('account');

        if (filled($id)) {
            // Account ids are UUIDs; PostgreSQL rejects anything else in the query.
            $account = Str::isUuid((string) $id) ? Account::query()->find($id) : null;

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
