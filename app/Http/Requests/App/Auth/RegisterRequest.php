<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Auth;

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Invite;
use App\Models\User;
use App\SocialHq\Companies\CompanySignup;
use App\SocialHq\Signup\SignupRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Social HQ: someone off the sign-up lists asks to start a company instead; that path has no `unique` (CompanySignup).
        $asksForCompany = $this->asksForCompany();

        return [
            'name' => ['required', 'string', 'max:255'],
            // Social HQ: the sign-up allow list runs before `unique` and stops there (bail), so an email that may not sign up never learns whether it has an account.
            'email' => $asksForCompany
                ? ['bail', 'required', 'string', 'lowercase', 'email', 'max:255']
                : ['bail', 'required', 'string', 'lowercase', 'email', 'max:255', SignupRules::allowListRule(fn (): bool => SignupRules::isOpenInvite($this->invite())), 'unique:'.User::class],
            'company_name' => $asksForCompany ? ['required', 'string', 'max:120'] : ['exclude'],
            'password' => ['required', Rules\Password::defaults()],
            'locale' => ['required', Rule::enum(Locale::class)],
            'timezone' => ['nullable', 'string'],
            'week_starts_on' => ['nullable', Rule::enum(WeekStart::class)],
            'time_format' => ['nullable', Rule::enum(TimeFormat::class)],
        ];
    }

    /**
     * Social HQ: whether this registration asks to start a company.
     */
    public function asksForCompany(): bool
    {
        return CompanySignup::asksForCompany($this->input('email'), fn (): bool => SignupRules::isOpenInvite($this->invite()));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['company_name.required' => __('socialhq_companies.signup.name_required')];
    }

    public function invite(): ?Invite
    {
        return Invite::fromId($this->string('invite')->toString());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $invite = $this->invite();

            if ($invite && ! $invite->isFor($this->string('email')->toString())) {
                $validator->errors()->add(
                    'email',
                    __('settings.members.flash.wrong_email'),
                );
            }
        });
    }
}
