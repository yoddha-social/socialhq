<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Inbox\WaitingForYou;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Waiting for you": the reviews and change requests in the current
 * workspace that this person should act on.
 */
class InboxController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        return Inertia::render('socialhq/Inbox', [
            'sections' => WaitingForYou::sections($user, $workspace),
        ]);
    }
}
