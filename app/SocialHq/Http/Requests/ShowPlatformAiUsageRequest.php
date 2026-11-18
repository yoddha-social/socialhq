<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the platform admins' AI usage page (every company). The
 * controller checks the platform admin.
 */
class ShowPlatformAiUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'nullable', Rule::in(array_keys(AiUsagePage::PERIODS))],
            'status' => ['sometimes', 'nullable', Rule::in([...array_column(AiUsageStatus::cases(), 'value'), AiUsagePage::PROBLEMS])],
            'feature' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9_]{1,64}$/'],
            'provider' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
            'user' => ['sometimes', 'nullable', 'uuid'],
            'company' => ['sometimes', 'nullable', 'integer', Rule::exists('shq_companies', 'id')],
        ];
    }
}
