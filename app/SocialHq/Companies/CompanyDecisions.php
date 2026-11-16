<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\Jobs\BlockCompanyAi;
use App\Support\PostApproval;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;

/**
 * Platform admins' decisions about a company. Each one locks the company's
 * row and checks its status first, so when two admins decide at once the
 * first wins and the second gets false (nothing changes then). Every
 * decision is recorded in shq_company_events.
 */
final class CompanyDecisions
{
    /**
     * Lets a pending company in: its owner can create workspaces. Only once
     * the owner confirmed their email.
     */
    public static function approve(Company $company, User $admin): bool
    {
        $decided = self::decide($company, $admin, CompanyStatus::Pending, CompanyStatus::Approved, CompanyEvent::APPROVED, null, requireVerifiedOwner: true);

        if ($decided !== null) {
            self::tellOwner($decided, new Mail\CompanyApproved($decided->id));
        }

        return $decided !== null;
    }

    public static function decline(Company $company, User $admin, string $note): bool
    {
        $decided = self::decide($company, $admin, CompanyStatus::Pending, CompanyStatus::Declined, CompanyEvent::DECLINED, $note);

        if ($decided !== null) {
            self::tellOwner($decided, new Mail\CompanyDeclined($decided->id));
        }

        return $decided !== null;
    }

    /**
     * Stops an approved company: nobody in it can use the app until it is restored.
     */
    public static function suspend(Company $company, User $admin, string $note): bool
    {
        $decided = self::decide($company, $admin, CompanyStatus::Approved, CompanyStatus::Suspended, CompanyEvent::SUSPENDED, $note);
        $decided !== null && self::blockAi($decided, true);

        return $decided !== null;
    }

    /**
     * Lets a paused company work again. Its posts that came due while it was
     * paused (still scheduled, their time passed) go back to drafts with
     * their content and channels, so a dated post (a festival greeting)
     * never goes out late and nothing publishes all at once.
     *
     * @return int|null how many posts went back to drafts, or null when the company wasn't paused (any more)
     */
    public static function restore(Company $company, User $admin): ?int
    {
        $decided = self::decide($company, $admin, CompanyStatus::Suspended, CompanyStatus::Approved, CompanyEvent::RESTORED, null);

        if ($decided === null) {
            return null;
        }

        self::blockAi($decided, false);

        return self::draftOverduePosts($decided);
    }

    private static function draftOverduePosts(Company $company): int
    {
        $moved = 0;

        Post::query()
            ->whereIn('workspace_id', Workspace::query()->where('account_id', $company->account_id)->select('id'))
            ->due()
            ->lazyById()
            ->each(function (Post $post) use (&$moved): void {
                $post->update([
                    'status' => PostStatus::Draft,
                    'scheduled_at' => null,
                    'schedule_mode' => null,
                    ...PostApproval::transition(PostStatus::Scheduled, PostStatus::Draft, null),
                ]);
                $moved++;
            });

        return $moved;
    }

    /**
     * A paused company's own LiteLLM key stops working there too.
     */
    private static function blockAi(Company $company, bool $blocked): void
    {
        $teamId = CompanyAi::query()->where('account_id', $company->account_id)->value('litellm_team_id');

        if (filled($teamId)) {
            BlockCompanyAi::dispatch((string) $teamId, $blocked)->afterCommit();
        }
    }

    /**
     * Whether the company may use the platform's own AI keys. Setting the
     * value it already has records nothing.
     */
    public static function usePlatformAi(Company $company, User $admin, bool $enabled): void
    {
        DB::transaction(function () use ($company, $admin, $enabled): void {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            if ($locked->uses_platform_ai === $enabled) {
                return;
            }

            $locked->forceFill(['uses_platform_ai' => $enabled])->save();
            $locked->record($enabled ? CompanyEvent::PLATFORM_AI_ON : CompanyEvent::PLATFORM_AI_OFF, $admin);
        });
    }

    /**
     * @return Company|null the decided company, or null when its status was no longer $from
     */
    private static function decide(Company $company, User $admin, CompanyStatus $from, CompanyStatus $to, string $action, ?string $note, bool $requireVerifiedOwner = false): ?Company
    {
        return DB::transaction(function () use ($company, $admin, $from, $to, $action, $note, $requireVerifiedOwner): ?Company {
            $locked = Company::query()->whereKey($company->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== $from) {
                return null;
            }

            if ($requireVerifiedOwner && $locked->account?->owner?->hasVerifiedEmail() !== true) {
                return null;
            }

            $locked->forceFill([
                'status' => $to,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
            $locked->record($action, $admin, $note);

            return $locked;
        });
    }

    private static function tellOwner(Company $company, Mailable $mailable): void
    {
        $owner = $company->account?->owner;

        if ($owner instanceof User) {
            SendNotification::dispatch($owner, Type::Collaboration, $mailable)->afterCommit();
        }
    }
}
