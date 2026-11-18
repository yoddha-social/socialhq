<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\ApiKey;

use App\Support\Requests\ApiKey\ApiKeyRequestRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool|Response
    {
        $user = $this->user();
        $workspace = $user?->currentWorkspace;

        // Social HQ: manageApiKeys refuses agents with a reason (AdminSettings).
        return $workspace === null ? false : Gate::forUser($user)->inspect('manageApiKeys', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ApiKeyRequestRules::store();
    }
}
