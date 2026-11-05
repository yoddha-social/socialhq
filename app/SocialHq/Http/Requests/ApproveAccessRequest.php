<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Signup\AccessRequests;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveAccessRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspaces' => ['required', 'array', 'min:1'],
            'workspaces.*' => ['string', Rule::in(AccessRequests::workspacesManagedBy($this->user())->pluck('id')->all())],
            'is_admin' => ['required', 'boolean'],
        ];
    }
}
