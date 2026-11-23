<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Middleware;

use App\Models\User;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Companies\CompanyRequests;
use App\SocialHq\Companies\CompanySignup;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Inbox\WaitingForYou;
use App\SocialHq\Notices\NoticeCreated;
use App\SocialHq\Notices\Notices;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\SignupRules;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The module's shared Inertia props (`page.props.socialhq`), set on every
 * request. A share made once when the app boots doesn't survive a server
 * that keeps the app between requests and flushes Inertia's shared props
 * after each one (Pest's browser server does; so would Octane), and the
 * frontend reads these props without checking they are there.
 *
 * One share for the whole module: a second Inertia::share('socialhq') would
 * replace this one. Each part is a closure, so a partial reload that doesn't
 * ask for it runs none of its queries.
 */
class ShareSocialHqProps
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('socialhq', [
            'approvals' => fn (): array => ['membersAlwaysReviewed' => ApprovalRules::membersAlwaysReviewed()],
            // Whether "Publish now" asks first (components/socialhq/publishNow.ts).
            'publishNow' => fn (): array => ['confirm' => (bool) config('socialhq.publish_now.confirm', true)],
            // Whether the calendar's post list starts open (pages/posts/Calendar.vue).
            'calendar' => fn (): array => ['postListOpen' => (bool) config('socialhq.calendar.post_list_open', true)],
            // The notification bell: how many notices are unread, and the private channel new ones arrive on.
            'notices' => function (): ?array {
                $user = auth()->user();

                if (! $user instanceof User || $user->currentWorkspace === null) {
                    return null;
                }

                return [
                    'unread' => Notices::unreadCount($user, $user->currentWorkspace),
                    'channel' => NoticeCreated::channelFor($user->id),
                ];
            },
            'aiGraphics' => fn (): array => [
                ...(function (): array {
                    $user = auth()->user();
                    $workspace = $user instanceof User ? $user->currentWorkspace : null;
                    $offered = $workspace !== null && AiGraphics::isOffered($user, $workspace);

                    return [
                        // "Generate with AI" shows (offered) and works (available); when it shows but
                        // can't work, `missing` says what the company's AI lacks (AiGraphics::missing()).
                        'offered' => $offered,
                        'available' => $offered && AiGraphics::canUse($user, $workspace),
                        'missing' => $offered ? AiGraphics::missing() : null,
                    ];
                })(),
                'maxReferences' => (int) config('socialhq.ai_graphics.max_reference_images', 3),
                'maxReferenceKilobytes' => (int) config('socialhq.ai_graphics.max_reference_kilobytes', 10240),
            ],
            'companyAi' => fn (): array => [
                // Settings › AI is the account owner's, for an approved company only.
                'canManage' => CompanyAiAccess::canManage(auth()->user() instanceof User ? auth()->user() : null),
            ],
            'signup' => function (): array {
                $joinsByEmail = SignupRules::isOpen();

                return [
                    // The login page's "Sign up": the company lists, or asking to start a company.
                    'open' => $joinsByEmail || CompanyRequests::enabled(),
                    // Whether a work email joins its team's company (the company name field then says it may stay empty).
                    'joinsByEmail' => $joinsByEmail,
                    // The register page's company name field (the server still decides on submit).
                    'companyRequests' => CompanySignup::showsCompanyField(),
                    // 0 for anyone who can't decide (the workspace menu and the Members banner read it).
                    'pendingRequests' => auth()->user() instanceof User ? AccessRequests::pendingCountFor(auth()->user()) : 0,
                ];
            },
            // The sidebar's "Waiting for you" count: reviews and change requests for this person.
            'inbox' => function (): array {
                $user = auth()->user();
                $workspace = $user instanceof User ? $user->currentWorkspace : null;

                return ['count' => $workspace === null ? 0 : WaitingForYou::count($user, $workspace)];
            },
            // The platform admin link in the user menu, with the requests waiting for a decision.
            'platform' => function (): array {
                $isAdmin = PlatformAdmins::is(auth()->user());

                return ['admin' => $isAdmin, 'pendingCompanies' => $isAdmin ? CompanyRequests::awaitingDecisionCount() : 0];
            },
        ]);

        return $next($request);
    }
}
