<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\CompanyAi;

use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CredentialTier;
use App\SocialHq\Ai\Company\ProviderCatalogue;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Ai\Company\ProviderKeySettings;
use App\SocialHq\Ai\AiTask;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a provider key, with its name, plan, tasks and models. The key is
 * write-only: it is never flashed back as old input (SocialHqServiceProvider
 * adds it to dontFlash) and no error message repeats it. Its characters are
 * limited to what provider keys use, so it can't name a LiteLLM environment
 * variable ("os.environ/..."); model ids are checked the same way.
 */
class StoreProviderCredentialRequest extends FormRequest
{
    /** Provider keys are letters, digits, dots, dashes and underscores. */
    public const KEY_PATTERN = '/^[A-Za-z0-9._\-]{16,300}$/';

    /** A Cloudflare account id: 32 hexadecimal characters. */
    public const ACCOUNT_ID_PATTERN = '/^[a-f0-9]{32}$/';

    /** A model id as providers write them ("openai/gpt-oss-120b", "@cf/meta/llama-4", "x:free"); never an environment variable. */
    public const MODEL_PATTERN = '/^(?!.*os\.environ)[A-Za-z0-9@][A-Za-z0-9._:\/@\-]{0,149}$/i';

    public function authorize(): bool
    {
        return CompanyAiAccess::canManage($this->user());
    }

    public function provider(): ?ProviderCatalogue
    {
        return ProviderCatalogue::find((string) $this->input('provider'));
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

        return [
            'provider' => ['required', 'string', Rule::in(ProviderCatalogue::ids())],
            'label' => ['required', 'string', 'max:60'],
            'tier' => ['required', 'string', Rule::in($provider !== null ? array_map(fn (CredentialTier $item): string => $item->value, $provider->tiers()) : [])],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*' => ['string', 'distinct', Rule::in($tasks)],
            'models' => ['nullable', 'array:'.implode(',', $tasks)],
            'models.*' => ['nullable', 'string', 'regex:'.self::MODEL_PATTERN],
            'provider_key' => ['required', 'string', 'regex:'.self::KEY_PATTERN],
            'account_id' => $provider?->needsAccountId() ? ['required', 'string', 'regex:'.self::ACCOUNT_ID_PATTERN] : ['prohibited'],
            'confirmed' => $tier !== null && $provider?->confirmation($tier) !== null ? ['accepted'] : ['nullable'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = CompanyAiAccess::accountFor($this->user());

                if ($account === null || $validator->errors()->has('provider')) {
                    return;
                }

                $existing = ProviderCredential::query()->forAccount($account)->get(['id', 'label']);
                $label = Str::lower(trim((string) $this->input('label')));

                if ($label !== '' && $existing->contains(fn (ProviderCredential $credential): bool => Str::lower((string) $credential->label) === $label)) {
                    $validator->errors()->add('label', __('socialhq_ai.validation.label_taken'));
                }

                if ($existing->count() >= (int) config('socialhq.company_ai.max_keys', 20)) {
                    $validator->errors()->add('provider', __('socialhq_ai.validation.too_many'));
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
            'provider_key.required' => __('socialhq_ai.validation.key_required'),
            'provider_key.regex' => __('socialhq_ai.validation.key_format'),
            'account_id.required' => __('socialhq_ai.validation.account_id'),
            'account_id.regex' => __('socialhq_ai.validation.account_id'),
            'confirmed.accepted' => __("socialhq_ai.validation.confirm_{$confirmation}"),
        ];
    }

    /**
     * The key's settings. Every task ticked is stored as "all" (null), so a
     * task the catalogue adds later reaches the key; a model left empty or
     * equal to the catalogue's is not stored, so it follows the catalogue.
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

    /**
     * Non-secret fields stored with the key.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->provider()?->needsAccountId() ? ['account_id' => (string) $this->validated('account_id')] : [];
    }
}
