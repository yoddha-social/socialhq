<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Companies\CompanyAccess;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\PlatformAdmins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What everyone in a suspended company sees, and who to contact: the owner
 * writes to the platform, members to their company's owner.
 */
class CompanyPausedController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $company = CompanyAccess::companyOf($user);

        if ($company?->status !== CompanyStatus::Suspended) {
            return redirect()->route('app.home');
        }

        $isOwner = $user->isAccountOwner();
        $owner = $company->account?->owner;

        return Inertia::render('socialhq/CompanyPaused', [
            'company' => $company->name,
            'isOwner' => $isOwner,
            'note' => $isOwner ? $company->decision_note : null,
            'contact' => $isOwner
                ? CompanyAccess::platformContact($company)
                : $owner?->email,
            'ownerName' => $isOwner ? null : $owner?->name,
            'isPlatformAdmin' => PlatformAdmins::is($user),
        ]);
    }
}
