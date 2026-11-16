<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CredentialTier;
use App\SocialHq\Ai\Company\ProviderCatalogue;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Ai\Company\ProviderKeySettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Renaming a key, or changing its plan, tasks or models, without sending
 * the key again. Moving to a plan with a checkbox asks for it again.
 */
class UpdateProviderKeySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = CompanyAiAccess::accountFor($this->user());

        return $account !== null && $this->credential()?->account_id === $account->id;
    }

    public function credential(): ?ProviderCredential
    {
        $credential = $this->route('credential');

        return $credential instanceof ProviderCredential ? $credential : null;
    }

    public function provider(): ?ProviderCatalogue
    {
        return $this->credential()?->catalogue();
    }

    public function tier(): ?CredentialTier
    {
        $tier = CredentialTier::tryFrom((string) $this->input('tier'));

        return $tier !== null && $this->provider()?->offers($tier) ? $tier : null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $provider = $this->provider();
        $tier = $this->tier();
        $tasks = $provider !== null && $tier !== null ? array_map(fn (AiTask $task): string => $task->value, $provider->tasks($tier)) : [];
        $asksAgain = $tier !== null && $tier !== $this->credential()?->tier && $provider?->confirmation($tier) !== null;

        return [
            'label' => ['required', 'string', 'max:60'],
            'tier' => ['required', 'string', Rule::in($provider !== null ? array_map(fn (CredentialTier $item): string => $item->value, $provider->tiers()) : [])],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*' => ['string', 'distinct', Rule::in($tasks)],
            'models' => ['nullable', 'array:'.implode(',', $tasks)],
            'models.*' => ['nullable', 'string', 'regex:'.StoreProviderCredentialRequest::MODEL_PATTERN],
            'confirmed' => $asksAgain ? ['accepted'] : ['nullable'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $credential = $this->credential();
                $label = Str::lower(trim((string) $this->input('label')));

                if ($credential === null || $label === '') {
                    return;
                }

                $taken = ProviderCredential::query()
                    ->where('account_id', $credential->account_id)
                    ->whereKeyNot($credential->id)
                    ->get(['id', 'label'])
                    ->contains(fn (ProviderCredential $other): bool => Str::lower((string) $other->label) === $label);

                if ($taken) {
                    $validator->errors()->add('label', __('socialhq_ai.validation.label_taken'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $confirmation = $this->tier() !== null ? $this->provider()?->confirmation($this->tier()) : null;

        return [
            'label.required' => __('socialhq_ai.validation.label_required'),
            'label.max' => __('socialhq_ai.validation.label_long'),
            'tier.required' => __('socialhq_ai.validation.tier'),
            'tier.in' => __('socialhq_ai.validation.tier'),
            'tasks.required' => __('socialhq_ai.validation.tasks'),
            'tasks.min' => __('socialhq_ai.validation.tasks'),
            'tasks.*.in' => __('socialhq_ai.validation.tasks'),
            'models.array' => __('socialhq_ai.validation.model_format'),
            'models.*.regex' => __('socialhq_ai.validation.model_format'),
            'confirmed.accepted' => __("socialhq_ai.validation.confirm_{$confirmation}"),
        ];
    }

    /**
     * The key's new settings, stored like a new key's (every task ticked
     * is "all"; a model equal to the catalogue's is not stored).
     */
    public function settings(): ProviderKeySettings
    {
        $provider = $this->provider();
        $tier = $this->tier() ?? CredentialTier::DEFAULT;
        $offered = $provider?->tasks($tier) ?? [];
        $tasks = array_values(array_unique((array) $this->validated('tasks')));
        $models = [];

        foreach ((array) ($this->validated('models') ?? []) as $task => $model) {
            $default = AiTask::tryFrom((string) $task) !== null ? $provider?->model(AiTask::from((string) $task), $tier) : null;

            if (filled($model) && $provider !== null && $provider->fullModel(trim((string) $model)) !== $default) {
                $models[(string) $task] = $provider->shortModel(trim((string) $model));
            }
        }

        return new ProviderKeySettings(
            label: trim((string) $this->validated('label')),
            tier: $tier,
            tasks: count($tasks) === count($offered) ? null : $tasks,
            models: $models,
        );
    }
}
