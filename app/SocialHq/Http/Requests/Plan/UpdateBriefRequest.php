<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefTeam;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a brief. The assignee must be someone in the workspace.
 */
class UpdateBriefRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->user()?->currentWorkspace;

        return [
            'kind' => ['required', Rule::enum(BriefKind::class)],
            'assignee_id' => ['nullable', 'string', Rule::in($workspace === null ? [] : BriefTeam::ids($workspace))],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'format_notes' => ['nullable', 'string', 'max:2000'],
            'on_screen_text' => ['nullable', 'string', 'max:5000'],
            'direction' => ['nullable', 'string', 'max:10000'],
            'references' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
