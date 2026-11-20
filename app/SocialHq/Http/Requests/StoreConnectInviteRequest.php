<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConnectInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccounts', $this->user()->currentWorkspace);
    }

    /**
     * The link is made for the channel in the address; nothing is sent.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
