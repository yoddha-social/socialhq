<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RejectPostRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('approve', $this->route('post'));
    }

    /**
     * The optional reason is kept as a note on the post and sent to the
     * requester in the decision email (Social HQ, PATCHES.md).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
