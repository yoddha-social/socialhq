<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Events\BrandEventCountries;
use App\SocialHq\Http\Requests\Plan\Concerns\ResolvesPlanContext;
use App\SocialHq\Knowledge\BrandKnowledge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The countries whose dates a brand plans around (owner decision
 * 2026-11-24). Workspace admins choose them, as they edit the brand.
 */
class UpdateBrandEventCountriesRequest extends FormRequest
{
    use ResolvesPlanContext;

    public function authorize(): bool
    {
        return BrandKnowledge::canEdit($this->user(), $this->planWorkspace());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'countries' => ['present', 'array', 'max:'.BrandEventCountries::MAX],
            'countries.*' => ['string', 'distinct', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }
}
