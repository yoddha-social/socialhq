<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Companies\PlatformAdmins;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class UpdateCompanyPlatformAiRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
        ];
    }
}
