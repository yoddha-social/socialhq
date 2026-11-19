<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Channels\ChannelPage;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChannelNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccounts', $this->user()->currentWorkspace);
    }

    /**
     * An empty name goes back to the name the network reports.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['present', 'nullable', 'string', 'max:'.ChannelPage::NAME_MAX_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
