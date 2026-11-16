<?php

declare(strict_types=1);

namespace App\SocialHq\Secrets;

use App\SocialHq\Ai\Company\CompanyAi;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * After a rotation of SOCIALHQ_SECRETS_KEY: re-encrypts every secret
 * (EncryptedSecret column) that only a previous key opens with the current
 * key, so SOCIALHQ_SECRETS_PREVIOUS_KEYS can be emptied. Run it with
 * --dry-run first; it changes nothing then.
 */
class SecretsReencryptCommand extends Command
{
    /**
     * Every model column cast to EncryptedSecret. A new one goes here
     * (SecretsReencryptCommandTest fails otherwise).
     *
     * @var array<class-string<Model>, list<string>>
     */
    public const array COLUMNS = [
        CompanyAi::class => ['virtual_key'],
    ];

    protected $signature = 'socialhq:secrets-reencrypt {--dry-run : Only count what would be re-encrypted}';

    protected $description = 'Re-encrypt company secrets with the current SOCIALHQ_SECRETS_KEY (after a rotation)';

    public function handle(): int
    {
        if (! SecretBox::isConfigured()) {
            $this->error((new SecretsKeyMissing)->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $unreadable = 0;

        foreach (self::COLUMNS as $model => $columns) {
            foreach ($columns as $column) {
                [$current, $reencrypted, $failed] = $this->reencrypt($model, $column, $dryRun);
                $unreadable += $failed;
                $rows[] = [class_basename($model).".{$column}", $current, $reencrypted, $failed];
            }
        }

        $this->table(['Column', 'Already current', $dryRun ? 'Would re-encrypt' : 'Re-encrypted', 'Unreadable'], $rows);

        if ($unreadable > 0) {
            $this->error('Some secrets open with no current or previous key: add the key that wrote them to SOCIALHQ_SECRETS_PREVIOUS_KEYS, or set them again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array{int, int, int} already current, re-encrypted, unreadable
     */
    private function reencrypt(string $model, string $column, bool $dryRun): array
    {
        $counts = [0, 0, 0];

        $model::query()->whereNotNull($column)->chunkById(100, function ($records) use ($column, $dryRun, &$counts): void {
            foreach ($records as $record) {
                $payload = (string) $record->getRawOriginal($column);

                if ($payload === '' || SecretBox::opensWithCurrentKey($payload)) {
                    $counts[0]++;

                    continue;
                }

                try {
                    $secret = SecretBox::decrypt($payload);
                } catch (DecryptException) {
                    $this->warn("{$record->getTable()} #{$record->getKey()}: {$column} opens with no key.");
                    $counts[2]++;

                    continue;
                }

                if (! $dryRun) {
                    // Straight to the column: the cast would encrypt again, and this is no edit.
                    $record->newQuery()->whereKey($record->getKey())->toBase()->update([$column => SecretBox::encrypt($secret)]);
                }

                $counts[1]++;
            }
        });

        return $counts;
    }
}
