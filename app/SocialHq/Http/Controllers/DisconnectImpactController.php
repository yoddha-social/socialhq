<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\SocialHq\Channels\DisconnectImpact;
use App\SocialHq\Http\Requests\ShowDisconnectImpactRequest;
use Illuminate\Http\JsonResponse;

/**
 * What disconnecting a channel would delete, read when the disconnect dialog opens.
 */
class DisconnectImpactController extends Controller
{
    use EnsuresChannelInCurrentWorkspace;

    public function show(ShowDisconnectImpactRequest $request, SocialAccount $account): JsonResponse
    {
        $this->ensureCurrentWorkspace($request, $account);

        return response()->json(DisconnectImpact::of($account));
    }
}
