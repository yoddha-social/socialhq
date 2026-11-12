<?php

declare(strict_types=1);

namespace App\SocialHq\Secrets;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for a column holding a secret encrypted by SecretBox.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedSecret implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return blank($value) ? null : SecretBox::decrypt((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return blank($value) ? null : SecretBox::encrypt((string) $value);
    }
}
