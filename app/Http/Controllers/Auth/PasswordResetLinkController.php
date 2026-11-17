<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\Auth\SendPasswordResetLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * Social HQ: the answer is the same whether or not the email has an
     * account (and whether a link went out a moment ago), and the email is
     * sent after the response, so neither the text nor the timing tells. The
     * broker still sends at most one link a minute per account.
     */
    public function store(SendPasswordResetLinkRequest $request): RedirectResponse
    {
        $email = $request->validated('email');

        defer(fn () => Password::sendResetLink(['email' => $email]));

        return redirect()->route('password.request')->with('status', __(Password::RESET_LINK_SENT));
    }
}
