<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\Website\WebsiteCrawler;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Uri;

class StoreWebsiteCrawlRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (self::normalizedUrl((string) $value) === null) {
                        $fail(__('socialhq_knowledge.website.errors.url'));
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
            'url.required' => __('socialhq_knowledge.website.errors.url'),
        ];
    }

    /**
     * The address as read: https:// when no scheme was typed, then normalized.
     */
    public static function normalizedUrl(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return null;
        }

        $url = preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1 ? $value : "https://{$value}";
        $normal = WebsiteCrawler::normalize($url);

        // A host with a dot: a site on the internet, not "localhost" or a bare name.
        return $normal !== null && str_contains((string) Uri::of($normal)->host(), '.') ? $normal : null;
    }

    public function crawlUrl(): string
    {
        return self::normalizedUrl((string) $this->validated('url')) ?? abort(422);
    }
}
