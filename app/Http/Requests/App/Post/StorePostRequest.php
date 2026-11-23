<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\Status;
use App\Models\SocialAccount;
use App\SocialHq\Composer\ComposerRepeat;
use App\SocialHq\Plan\ItemPosts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
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
            'status' => ['required', Rule::in([Status::Draft->value, Status::Scheduled->value, Status::Publishing->value])],
            'content' => ['sometimes', 'nullable', 'string'],
            'media' => ['sometimes', 'array'],
            'scheduled_at' => ['nullable', 'date'],
            'queue' => ['nullable', 'string'],
            'queue_slot' => ['nullable', 'date'],
            'label_ids' => ['sometimes', 'array'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => ['required', 'uuid'],
            'destinations.*.content_type' => ['required', 'string'],
            'destinations.*.meta' => ['sometimes', 'array'],
            'destinations.*.content' => ['sometimes', 'nullable', 'string'],
            'destinations.*.media' => ['sometimes', 'array'],
            'recover_post_id' => ['sometimes', 'uuid'],
            // Social HQ: the repeat picked in the composer's footer (PATCHES.md row 233).
            'recurrence' => ['sometimes', 'nullable', 'array'],
            'recurrence.interval' => ['required_with:recurrence', 'integer', 'min:1', 'max:'.UpdatePostRecurrenceRequest::MAX_INTERVAL],
            'recurrence.frequency' => ['required_with:recurrence', Rule::enum(RecurrenceFrequency::class)],
            'recurrence.times' => ['required_with:recurrence', 'integer', 'min:1', 'max:'.UpdatePostRecurrenceRequest::MAX_TIMES],
            // Social HQ: the plan item the post is made from (PATCHES.md row 260).
            'plan_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists(ItemPosts::ITEMS_TABLE, 'id')->where('workspace_id', $this->user()?->current_workspace_id)],
        ];
    }

    /**
     * Social HQ: a repeat needs someone who publishes directly and a set time
     * whose series ends before the latest instant a post may be scheduled at.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $rule = $this->input('recurrence');

                if (blank($rule) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $workspace = $this->user()->currentWorkspace;

                if ($workspace === null || Gate::denies('publishDirectly', $workspace)) {
                    $validator->errors()->add('recurrence', __('socialhq.composer.repeat.errors.approval'));

                    return;
                }

                if ($this->input('status') !== Status::Scheduled->value || blank($this->input('scheduled_at')) || filled($this->input('queue'))) {
                    $validator->errors()->add('recurrence', __('posts.recurrence.errors.not_scheduled'));

                    return;
                }

                $scheduledAt = Carbon::parse($this->input('scheduled_at'));
                $timezones = SocialAccount::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('id', collect($this->input('destinations'))->pluck('social_account_id')->filter()->all())
                    ->pluck('timezone');

                if ($timezones->contains(fn (?string $timezone): bool => ComposerRepeat::endsTooLate($scheduledAt, $timezone, $rule))) {
                    $validator->errors()->add('recurrence', __('posts.recurrence.errors.too_far'));
                }
            },
        ];
    }
}
