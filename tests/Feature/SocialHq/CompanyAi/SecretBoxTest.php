<?php

declare(strict_types=1);

use App\Models\Account;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Secrets\SecretBox;
use App\SocialHq\Secrets\SecretsKeyMissing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

function secretsKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

test('a secret round trips with the secrets key and is not readable with APP_KEY', function () {
    config(['socialhq.secrets.key' => secretsKey(), 'socialhq.secrets.previous_keys' => []]);

    $payload = SecretBox::encrypt('sk-team-key-1234');

    expect($payload)->not->toContain('sk-team-key-1234')
        ->and(SecretBox::decrypt($payload))->toBe('sk-team-key-1234')
        ->and(fn () => decrypt($payload, false))->toThrow(DecryptException::class);
});

test('rotation keeps old secrets readable through the previous keys', function () {
    $old = secretsKey();
    config(['socialhq.secrets.key' => $old]);
    $payload = SecretBox::encrypt('sk-team-key-1234');

    config(['socialhq.secrets.key' => secretsKey()]);
    expect(fn () => SecretBox::decrypt($payload))->toThrow(DecryptException::class);

    config(['socialhq.secrets.previous_keys' => [$old]]);
    expect(SecretBox::decrypt($payload))->toBe('sk-team-key-1234');
});

test('nothing is encrypted without a proper secrets key, or with APP_KEY reused', function (?string $key) {
    config(['socialhq.secrets.key' => $key === 'app' ? config('app.key') : $key]);

    expect(SecretBox::isConfigured())->toBeFalse()
        ->and(fn () => SecretBox::encrypt('sk-team-key-1234'))->toThrow(SecretsKeyMissing::class);
})->with([
    'missing' => [null],
    'not base64' => ['plain-text-key'],
    'too short' => ['base64:'.base64_encode('short')],
    'APP_KEY' => ['app'],
]);

test('a company\'s virtual key is stored encrypted', function () {
    config(['socialhq.secrets.key' => secretsKey()]);
    $account = Account::factory()->create();

    $ai = CompanyAi::query()->create(['account_id' => $account->id, 'litellm_team_id' => 'shq-co-test', 'virtual_key' => 'sk-team-key-1234']);

    $raw = (string) DB::table('shq_company_ai')->where('id', $ai->id)->value('virtual_key');

    expect($raw)->not->toContain('sk-team-key-1234')
        ->and($ai->fresh()->virtual_key)->toBe('sk-team-key-1234')
        ->and($ai->toArray())->not->toHaveKey('virtual_key');
});
