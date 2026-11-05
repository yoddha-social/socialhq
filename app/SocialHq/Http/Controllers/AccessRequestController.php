<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\ApproveAccessRequest;
use App\SocialHq\Signup\AccessRequest;
use App\SocialHq\Signup\AccessRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admins let people who signed up in (giving them workspaces) or turn them away.
 */
class AccessRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(AccessRequests::canDecide($user), 403);

        return Inertia::render('socialhq/AccessRequests', [
            'requests' => AccessRequest::query()
                ->where('account_id', $user->account_id)
                ->awaitingDecision()
                ->oldest()
                ->get()
                ->map(fn (AccessRequest $accessRequest): array => [
                    'id' => $accessRequest->id,
                    'name' => $accessRequest->name,
                    'email' => $accessRequest->email,
                    'requested_at' => $accessRequest->created_at?->toIso8601String(),
                ]),
            'workspaces' => AccessRequests::workspacesManagedBy($user)
                ->map(fn (Workspace $workspace): array => ['id' => $workspace->id, 'name' => $workspace->name])
                ->values(),
        ]);
    }

    public function approve(ApproveAccessRequest $request, int $accessRequest): RedirectResponse
    {
        $found = $this->find($request, $accessRequest);

        AccessRequests::approve($found, $request->user(), $request->validated('workspaces'), $request->boolean('is_admin'));

        session()->flash('flash.banner', __('socialhq.signup.requests.approved', ['name' => $found->name]));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function decline(Request $request, int $accessRequest): RedirectResponse
    {
        $found = $this->find($request, $accessRequest);

        AccessRequests::decline($found, $request->user());

        session()->flash('flash.banner', __('socialhq.signup.requests.declined', ['name' => $found->name]));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    /**
     * A request of the decider's own account still waiting for a decision;
     * anything else is not found.
     */
    private function find(Request $request, int $id): AccessRequest
    {
        $user = $request->user();
        abort_unless(AccessRequests::canDecide($user), 403);

        return AccessRequest::query()->where('account_id', $user->account_id)->awaitingDecision()->find($id)
            ?? throw new NotFoundHttpException;
    }
}
