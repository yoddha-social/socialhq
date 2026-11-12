<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Companies\PlatformAdmins;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declining or suspending a company: the note says why, and the company's
 * owner reads it.
 */
class DecideCompanyRequest extends FormRequest
{
    /**
     * Platform pages are hidden from everyone else: 404 before validation.
     */
    public function authorize(): bool
    {
        abort_unless(Gate::allows(PlatformAdmins::GATE), Response::HTTP_NOT_FOUND);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('socialhq_companies.platform.note_required'),
            'note.max' => __('socialhq_companies.platform.note_too_long'),
        ];
    }
}
