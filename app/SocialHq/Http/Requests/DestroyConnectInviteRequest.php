<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyConnectInviteRequest extends FormRequest
{
    /**
     * Whoever may make a link may cancel one: the workspace's admins.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manageAccounts', $this->user()->currentWorkspace);
    }

    /**
     * The link is the one in the address; nothing is sent.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
