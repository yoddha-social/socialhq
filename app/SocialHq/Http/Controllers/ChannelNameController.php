<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\SocialHq\Channels\ChannelPage;
use App\SocialHq\Http\Requests\UpdateChannelNameRequest;
use Illuminate\Http\RedirectResponse;

/**
 * A channel's "Name in Social HQ".
 */
class ChannelNameController extends Controller
{
    use EnsuresChannelInCurrentWorkspace;

    public function update(UpdateChannelNameRequest $request, SocialAccount $account): RedirectResponse
    {
        $this->ensureCurrentWorkspace($request, $account);

        ChannelPage::rename($account, $request->validated('name'));

        return back();
    }
}
