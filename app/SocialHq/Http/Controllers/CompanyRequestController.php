<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Companies\CompanyAccess;
use App\SocialHq\Companies\CompanyRequests;
use App\SocialHq\Companies\CompanySignup;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Http\Requests\StoreCompanyRequest;
use App\SocialHq\Http\Requests\UpdateCompanyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking to start a company, from the requester's side: the "check your
 * email" page after signing up, the waiting page (pending or declined),
 * renaming while waiting, asking again after a decline, and the page for
 * someone with no workspace who can't create one.
 */
class CompanyRequestController extends Controller
{
    /**
     * After the register form (no one is logged in): what happens next.
     */
    public function requested(Request $request): Response
    {
        return Inertia::render('socialhq/CompanyRequested', [
            'email' => $request->session()->get(CompanySignup::SESSION_EMAIL),
        ]);
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $company = CompanyAccess::companyOf($user);

        if ($company?->status === CompanyStatus::Suspended) {
            return redirect()->route('socialhq.company.paused');
        }

        if ($company === null || ! $company->isWaiting()) {
            return redirect()->route('app.home');
        }

        return Inertia::render('socialhq/CompanyPending', [
            'company' => [
                'name' => $company->name,
                'status' => $company->status->value,
                'note' => $company->status === CompanyStatus::Declined ? $company->decision_note : null,
                'can_ask_again_at' => $company->canAskAgainAt()?->toIso8601String(),
            ],
            'email' => $user->email,
            'verified' => $user->hasVerifiedEmail(),
            'isOwner' => $user->isAccountOwner(),
            'isPlatformAdmin' => PlatformAdmins::is($user),
            'status' => session('status'),
        ]);
    }

    public function update(UpdateCompanyRequest $request): RedirectResponse
    {
        $company = CompanyRequests::waitingFor($request->user());
        abort_if($company === null, 404);

        if (! CompanyRequests::rename($company, $request->user(), $request->validated('name'))) {
            return redirect()->route('socialhq.company.waiting')->with('flash.error', __('socialhq_companies.waiting.already_decided'));
        }

        return redirect()->route('socialhq.company.waiting');
    }

    public function askAgain(Request $request): RedirectResponse
    {
        $company = CompanyRequests::waitingFor($request->user());
        abort_if($company === null, 404);

        if (! CompanyRequests::askAgain($company, $request->user())) {
            return redirect()->route('socialhq.company.waiting')->with('flash.error', __('socialhq_companies.waiting.cannot_ask_again'));
        }

        return redirect()->route('socialhq.company.waiting');
    }

    /**
     * Someone with no workspace who can't create one: how to get in.
     */
    public function start(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->can('create', Workspace::class)) {
            return redirect()->route('app.workspaces.create');
        }

        if ($user->current_workspace_id !== null) {
            return redirect()->route('app.home');
        }

        return Inertia::render('socialhq/CompanyStart', [
            'canAskForCompany' => CompanyAccess::mayAskForCompany($user),
            'isPlatformAdmin' => PlatformAdmins::is($user),
        ]);
    }

    /**
     * Asks to start a company from an account the person already has (an
     * invited person's own empty account).
     */
    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(CompanyAccess::mayAskForCompany($user), 403);

        CompanyRequests::open($user, $user->account, $request->validated('name'));
        CompanyRequests::notifyPlatformAdmins($user);

        return redirect()->route('socialhq.company.waiting');
    }
}
