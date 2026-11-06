<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\Look\LogoFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreBrandLogoRequest extends FormRequest
{
    private ?LogoFile $logo = null;

    public static function maxKilobytes(): int
    {
        return max(64, (int) config('socialhq.knowledge.look.logo_kilobytes', 2048));
    }

    /**
     * The type is decided by content (LogoFile), never by the name or the
     * MIME type the browser sent.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'max:'.self::maxKilobytes(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->logo = $value instanceof UploadedFile ? LogoFile::read($value->getRealPath()) : null;

                    if ($this->logo === null) {
                        $fail(__('socialhq_knowledge.look.errors.logo_type'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => __('socialhq_knowledge.look.errors.logo_required'),
            'logo.max' => __('socialhq_knowledge.look.errors.logo_size', ['size' => intdiv(self::maxKilobytes(), 1024) ?: 1]),
        ];
    }

    public function logo(): LogoFile
    {
        return $this->logo ?? abort(422);
    }
}
