<?php

declare(strict_types=1);

use App\Models\Account;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Secrets\SecretBox;
use App\SocialHq\Secrets\SecretsReencryptCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function newSecretsKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function storedCompanyKey(string $key): CompanyAi
{
    return CompanyAi::query()->create([
        'account_id' => Account::factory()->create()->id,
        'litellm_team_id' => 'shq-co-'.Str::random(8),
        'virtual_key' => $key,
    ]);
}

test('socialhq:secrets-key prints a key SecretBox accepts', function () {
    expect(Artisan::call('socialhq:secrets-key'))->toBe(0);

    $printed = trim(Artisan::output());
    config(['socialhq.secrets.key' => $printed]);

    expect($printed)->toStartWith('base64:')
        ->and(strlen((string) base64_decode(Str::after($printed, 'base64:'), true)))->toBe(32)
        ->and(SecretBox::isConfigured())->toBeTrue();
});

test('after a rotation, re-encrypt moves secrets to the current key; a dry run changes nothing', function () {
    $old = newSecretsKey();
    config(['socialhq.secrets.key' => $old, 'socialhq.secrets.previous_keys' => []]);
    $ai = storedCompanyKey('sk-team-key-1234');
    $raw = fn (): string => (string) DB::table('shq_company_ai')->where('id', $ai->id)->value('virtual_key');
    $before = $raw();

    config(['socialhq.secrets.key' => newSecretsKey(), 'socialhq.secrets.previous_keys' => [$old]]);
    $current = storedCompanyKey('sk-team-key-5678');

    $this->artisan('socialhq:secrets-reencrypt', ['--dry-run' => true])->assertSuccessful();
    expect($raw())->toBe($before);

    $this->artisan('socialhq:secrets-reencrypt')->assertSuccessful();

    // The previous key can go now.
    config(['socialhq.secrets.previous_keys' => []]);

    expect($raw())->not->toBe($before)
        ->and(SecretBox::opensWithCurrentKey($raw()))->toBeTrue()
        ->and($ai->fresh()->virtual_key)->toBe('sk-team-key-1234')
        ->and($current->fresh()->virtual_key)->toBe('sk-team-key-5678');
});

test('re-encrypt fails and changes nothing for a secret no key opens', function () {
    config(['socialhq.secrets.key' => newSecretsKey()]);
    $ai = storedCompanyKey('sk-team-key-1234');
    $before = (string) DB::table('shq_company_ai')->where('id', $ai->id)->value('virtual_key');

    config(['socialhq.secrets.key' => newSecretsKey(), 'socialhq.secrets.previous_keys' => []]);

    $this->artisan('socialhq:secrets-reencrypt')->assertFailed();

    expect((string) DB::table('shq_company_ai')->where('id', $ai->id)->value('virtual_key'))->toBe($before);
});

test('re-encrypt refuses to run without a secrets key', function () {
    config(['socialhq.secrets.key' => null]);

    $this->artisan('socialhq:secrets-reencrypt')->assertFailed();
});

test('every EncryptedSecret column is one re-encrypt knows', function () {
    $models = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => str_contains($file->getContents(), 'EncryptedSecret::class'))
        ->map(fn ($file): string => 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->values()
        ->all();

    expect($models)->toEqualCanonicalizing(array_keys(SecretsReencryptCommand::COLUMNS));
});
