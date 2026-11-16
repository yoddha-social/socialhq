<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefTeam;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a brief. The assignee must be someone in the workspace, and
 * the kind is the one the item's creative needs.
 */
class UpdateBriefRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspace = $this->user()?->currentWorkspace;
        $assignees = $workspace === null ? [] : BriefTeam::ids($workspace);

        $brief = $workspace === null ? null : Brief::query()
            ->with('item')
            ->where('workspace_id', $workspace->id)
            ->where('item_id', (int) $this->route('item'))
            ->first();

        // The current assignee stays allowed after leaving the workspace, so other fields can still be saved.
        if ($brief?->assignee_id !== null) {
            $assignees[] = $brief->assignee_id;
        }

        return [
            'kind' => ['required', Rule::enum(BriefKind::class), ...($brief === null ? [] : [Rule::in([($brief->item?->creative->briefKind() ?? $brief->kind)->value])])],
            'assignee_id' => ['nullable', 'string', Rule::in($assignees)],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'format_notes' => ['nullable', 'string', 'max:2000'],
            'on_screen_text' => ['nullable', 'string', 'max:5000'],
            'direction' => ['nullable', 'string', 'max:10000'],
            'references' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.in' => __('socialhq_production.brief.errors.kind_fixed'),
        ];
    }
}
