<?php

declare(strict_types=1);

namespace App\SocialHq\Secrets;

use Illuminate\Console\Command;

/**
 * Prints a new SOCIALHQ_SECRETS_KEY for the environment. It writes nothing:
 * the operator puts it where the server's secrets live.
 */
class SecretsKeyCommand extends Command
{
    protected $signature = 'socialhq:secrets-key';

    protected $description = 'Generate a key for SOCIALHQ_SECRETS_KEY (company secrets, separate from APP_KEY)';

    public function handle(): int
    {
        $this->line('base64:'.base64_encode(random_bytes(32)));

        return self::SUCCESS;
    }
}
