<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowDisconnectImpactRequest extends FormRequest
{
    /**
     * Only those who may disconnect the channel see what it would delete.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manageAccounts', $this->user()->currentWorkspace);
    }

    /**
     * The channel is in the address; nothing is sent.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
