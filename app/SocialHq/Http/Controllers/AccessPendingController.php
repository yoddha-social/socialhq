<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Signup\AccessRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessPendingController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (AccessRequests::pendingFor($user) === null) {
            return redirect()->route('app.home');
        }

        return Inertia::render('socialhq/AccessPending', [
            'email' => $user->email,
            'verified' => $user->hasVerifiedEmail(),
            'status' => session('status'),
        ]);
    }
}
