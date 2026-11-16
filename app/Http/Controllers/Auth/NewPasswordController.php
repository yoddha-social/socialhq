<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\Auth\ResetPasswordRequest;
use App\Support\Auth\PasswordRequirements;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     *
     * Social HQ: a used or expired link says so at once and offers a new one,
     * instead of showing the form. Only someone holding the token learns
     * anything, and an unknown email reads the same as a bad token.
     */
    public function create(Request $request): Response
    {
        $email = Str::lower(trim((string) $request->query('email')));
        $token = (string) $request->route('token');
        $user = $email === '' ? null : Password::broker()->getUser(['email' => $email]);

        return Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $token,
            'tokenValid' => $user !== null && Password::broker()->tokenExists($user, $token),
            'passwordRequirements' => PasswordRequirements::checklist(),
        ]);
    }

    /**
     * Handle an incoming new password request.
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        // Social HQ: an unknown email reads as a bad link, never "no such user".
        return back()->withInput($request->only('email'))->withErrors(['email' => __(Password::INVALID_TOKEN)]);
    }
}
