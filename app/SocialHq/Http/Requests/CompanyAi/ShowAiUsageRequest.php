<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings › AI › Usage filters. The controller decides the company; a
 * workspace filter must be one of that company's.
 */
class ShowAiUsageRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', Rule::enum(AiUsageStatus::class)],
            'feature' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9_]{1,64}$/'],
            'provider' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
            'workspace' => ['sometimes', 'nullable', 'uuid', Rule::exists('workspaces', 'id')->where('account_id', $this->user()?->account_id)],
        ];
    }
}
