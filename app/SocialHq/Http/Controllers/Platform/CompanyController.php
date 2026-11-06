<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyDecisions;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Http\Requests\DecideCompanyRequest;
use App\SocialHq\Http\Requests\UpdateCompanyPlatformAiRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Platform admins decide which companies work on this server: requests to
 * approve or decline, companies to suspend or restore, and which may use
 * the platform's AI keys. Anyone else gets a 404.
 */
class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePlatformAdmin($request);

        $companies = Company::query()
            ->with(['account' => fn ($account) => $account->withCount(['workspaces', 'users'])->with('owner')])
            ->get();

        [$requests, $decided] = $companies->partition(fn (Company $company): bool => $company->status === CompanyStatus::Pending);

        return Inertia::render('socialhq/platform/Companies', [
            'tab' => $request->query('tab') === 'companies' ? 'companies' : 'requests',
            'requests' => $requests
                ->sortBy(fn (Company $company) => $company->requested_at ?? $company->created_at)
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'owner_name' => $company->account?->owner?->name,
                    'owner_email' => $company->account?->owner?->email,
                    'verified' => $company->account?->owner?->hasVerifiedEmail() === true,
                    'requested_at' => ($company->requested_at ?? $company->created_at)?->toIso8601String(),
                ])
                ->values(),
            'companies' => $decided
                ->sortBy(fn (Company $company): string => mb_strtolower($company->name))
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'owner_name' => $company->account?->owner?->name,
                    'owner_email' => $company->account?->owner?->email,
                    'workspaces_count' => (int) $company->account?->workspaces_count,
                    'members_count' => (int) $company->account?->users_count,
                    'status' => $company->status->value,
                    'uses_platform_ai' => $company->uses_platform_ai,
                    'decision_note' => $company->decision_note,
                    'decided_at' => $company->decided_at?->toIso8601String(),
                    'created_at' => $company->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function approve(Request $request, int $company): RedirectResponse
    {
        $found = $this->find($request, $company);

        if (! CompanyDecisions::approve($found, $request->user())) {
            return $this->notChanged($found);
        }

        return $this->done('approved', $found);
    }

    public function decline(DecideCompanyRequest $request, int $company): RedirectResponse
    {
        $found = $this->find($request, $company);

        if (! CompanyDecisions::decline($found, $request->user(), $request->validated('note'))) {
            return $this->notChanged($found);
        }

        return $this->done('declined', $found);
    }

    public function suspend(DecideCompanyRequest $request, int $company): RedirectResponse
    {
        $found = $this->find($request, $company);

        if (! CompanyDecisions::suspend($found, $request->user(), $request->validated('note'))) {
            return $this->notChanged($found);
        }

        return $this->done('suspended', $found);
    }

    public function restore(Request $request, int $company): RedirectResponse
    {
        $found = $this->find($request, $company);

        if (! CompanyDecisions::restore($found, $request->user())) {
            return $this->notChanged($found);
        }

        return $this->done('restored', $found);
    }

    public function platformAi(UpdateCompanyPlatformAiRequest $request, int $company): RedirectResponse
    {
        $found = $this->find($request, $company);

        CompanyDecisions::usePlatformAi($found, $request->user(), $request->boolean('enabled'));

        return back();
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        if (Gate::forUser($request->user())->denies(PlatformAdmins::GATE)) {
            throw new NotFoundHttpException;
        }
    }

    private function find(Request $request, int $id): Company
    {
        $this->authorizePlatformAdmin($request);

        return Company::query()->find($id) ?? throw new NotFoundHttpException;
    }

    private function done(string $decision, Company $company): RedirectResponse
    {
        return back()
            ->with('flash.banner', __("socialhq_companies.platform.flash.{$decision}", ['name' => $company->name]))
            ->with('flash.bannerStyle', 'success');
    }

    /**
     * Another platform admin decided first, or the owner hasn't confirmed their email.
     */
    private function notChanged(Company $company): RedirectResponse
    {
        $fresh = $company->fresh();
        $key = $fresh?->status === CompanyStatus::Pending ? 'not_verified' : 'already_decided';

        return back()->with('flash.error', __("socialhq_companies.platform.flash.{$key}"));
    }
}
