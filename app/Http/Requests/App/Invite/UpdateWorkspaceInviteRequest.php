<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Invite;

use App\Http\Requests\App\Invite\Concerns\ValidatesMemberAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Social HQ: changes what a pending invite lets its person do once they
 * accept it, with the same rules as inviting or editing a member.
 */
class UpdateWorkspaceInviteRequest extends FormRequest
{
    use ValidatesMemberAccess;

    public function authorize(): bool
    {
        $workspace = $this->user()->currentWorkspace;

        return $workspace === null || $this->user()->can('manageTeam', $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->memberAccessRules();
    }
}
