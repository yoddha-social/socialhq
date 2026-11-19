<?php

declare(strict_types=1);

namespace App\SocialHq\Notices;

use App\Enums\Notification\Type;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Mail\AccountDisconnected;
use App\Mail\PostApprovalRequested;
use App\Mail\PostApproved;
use App\Mail\PostAtRisk;
use App\Mail\PostNoteAdded;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Mail\PostRejected;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\ApprovalEmailPost;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

/**
 * The notification bell. Every notification TryPost emails goes through the
 * `SendNotification` job, which hands it here first: the same event becomes a
 * notice in the bell, whether or not the email is sent.
 */
class Notices
{
    /**
     * The rows of Settings › Notifications (TryPost's email fields). A notice
     * kind follows the "In the app" switch of its row.
     *
     * @var list<string>
     */
    public const array FIELDS = ['post_published', 'post_failed', 'account_disconnected', 'post_note_added', 'collaboration'];

    /** A channel that lost its connection is mentioned once in this many hours. */
    public const int DISCONNECTED_EVERY_HOURS = 24;

    private const int EXCERPT_LENGTH = 120;

    /**
     * Records what an outgoing notification is about. Never throws: a notice
     * that can't be written must not stop the email.
     */
    public static function fromNotification(User $user, Type $type, Mailable $mailable): void
    {
        rescue(function () use ($user, $type, $mailable): void {
            if (! self::wantsInApp($user, $type)) {
                return;
            }

            foreach (self::drafts($mailable) as $draft) {
                self::record($user, ...$draft);
            }
        });
    }

    public static function wantsInApp(User $user, Type $type): bool
    {
        $field = self::field($type);

        if ($field === null) {
            return false;
        }

        $stored = NoticePreference::query()->where('user_id', $user->id)->value('in_app');

        return (bool) data_get(is_array($stored) ? $stored : [], $field, true);
    }

    /**
     * @return array{in_app: array<string, bool>, live: bool}
     */
    public static function preferences(User $user): array
    {
        $preference = NoticePreference::query()->where('user_id', $user->id)->first();

        return [
            'in_app' => collect(self::FIELDS)
                ->mapWithKeys(fn (string $field): array => [$field => (bool) data_get($preference?->in_app ?? [], $field, true)])
                ->all(),
            'live' => $preference?->live ?? true,
        ];
    }

    /**
     * @param  array<string, bool>  $changes
     */
    public static function updatePreferences(User $user, array $changes): void
    {
        $preference = NoticePreference::query()->firstOrNew(['user_id' => $user->id]);
        $inApp = $preference->in_app ?? [];

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                $inApp[$field] = (bool) data_get($changes, $field);
            }
        }

        $preference->in_app = $inApp;

        if (array_key_exists('live', $changes)) {
            $preference->live = (bool) data_get($changes, 'live');
        }

        $preference->save();
    }

    public static function unreadCount(User $user, Workspace $workspace): int
    {
        return Notice::query()->listedFor($user, $workspace)->whereNull('read_at')->count();
    }

    /**
     * The row of Settings › Notifications a notification type belongs to.
     */
    private static function field(Type $type): ?string
    {
        return match ($type) {
            Type::PostPublished => 'post_published',
            Type::PostFailed => 'post_failed',
            Type::AccountDisconnected, Type::PostAtRisk => 'account_disconnected',
            Type::PostNoteAdded => 'post_note_added',
            Type::Collaboration => 'collaboration',
            default => null,
        };
    }

    /**
     * The notices one notification becomes: none for mail the bell doesn't
     * cover (invites, access requests, company decisions).
     *
     * @return list<array{0: NoticeKind, 1: string, 2: array<string, mixed>, 3?: string|null, 4?: string|null}>
     */
    private static function drafts(Mailable $mailable): array
    {
        return match (true) {
            $mailable instanceof PostPublished => [self::forPost(NoticeKind::PostPublished, $mailable->post, [
                'url' => $mailable->post->platform_url,
            ])],
            $mailable instanceof PostPublishFailed => [self::forPost(NoticeKind::PostFailed, $mailable->post, [
                'reason' => Str::limit((string) $mailable->post->error_message, self::EXCERPT_LENGTH),
                'reconnect' => $mailable->post->socialAccount !== null && $mailable->post->socialAccount->status !== AccountStatus::Connected,
            ])],
            $mailable instanceof PostApprovalRequested => self::forPostId(NoticeKind::ApprovalRequested, $mailable->postId, ['actor' => $mailable->requester->name]),
            $mailable instanceof PostApproved => self::forPostId(NoticeKind::PostApproved, $mailable->postId, ['actor' => $mailable->approver->name]),
            $mailable instanceof PostRejected => self::forPostId(NoticeKind::PostRejected, $mailable->postId, [
                'actor' => $mailable->approver->name,
                'reason' => Str::limit((string) $mailable->reason, self::EXCERPT_LENGTH),
            ]),
            $mailable instanceof PostNoteAdded => self::forPostId(NoticeKind::PostNote, (string) $mailable->note->post_id, [
                'actor' => $mailable->author->name,
                'note' => Str::limit(trim(strip_tags((string) $mailable->note->body)), self::EXCERPT_LENGTH),
            ]),
            $mailable instanceof AccountDisconnected => [self::forChannel($mailable->account)],
            $mailable instanceof WorkspaceConnectionsDisconnected => $mailable->disconnectedAccounts
                ->map(fn (SocialAccount $account): array => self::forChannel($account))
                ->values()
                ->all(),
            $mailable instanceof PostAtRisk => [[NoticeKind::PostsAtRisk, $mailable->workspace->id, ['count' => $mailable->count]]],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: NoticeKind, 1: string, 2: array<string, mixed>, 3: string|null, 4: string|null}
     */
    private static function forPost(NoticeKind $kind, Post $post, array $data): array
    {
        return [$kind, $post->workspace_id, [
            'channel' => $post->socialAccount?->display_label ?? $post->platform_name,
            'platform' => $post->platform?->value,
            'excerpt' => Str::limit(ApprovalEmailPost::excerpt($post), self::EXCERPT_LENGTH),
            ...$data,
        ], $post->id, $post->social_account_id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{0: NoticeKind, 1: string, 2: array<string, mixed>, 3: string|null, 4: string|null}>
     */
    private static function forPostId(NoticeKind $kind, string $postId, array $data): array
    {
        $post = Post::query()->with('socialAccount')->find($postId);

        return $post === null ? [] : [self::forPost($kind, $post, $data)];
    }

    /**
     * @return array{0: NoticeKind, 1: string, 2: array<string, mixed>, 3: null, 4: string}
     */
    private static function forChannel(SocialAccount $account): array
    {
        return [NoticeKind::ChannelDisconnected, $account->workspace_id, [
            'channel' => $account->display_label,
            'platform' => $account->platform->value,
        ], null, $account->id];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function record(User $user, NoticeKind $kind, string $workspaceId, array $data, ?string $postId = null, ?string $accountId = null): void
    {
        if ($kind === NoticeKind::ChannelDisconnected && self::mentionedLately($user, (string) $accountId)) {
            return;
        }

        $notice = Notice::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'kind' => $kind,
            'post_id' => $postId,
            'social_account_id' => $accountId,
            'data' => $data,
        ]);

        if (NoticePreference::query()->where('user_id', $user->id)->value('live') ?? true) {
            NoticeCreated::dispatch($notice);
        }
    }

    private static function mentionedLately(User $user, string $accountId): bool
    {
        return Notice::query()
            ->where('user_id', $user->id)
            ->where('kind', NoticeKind::ChannelDisconnected)
            ->where('social_account_id', $accountId)
            ->where('created_at', '>=', now()->subHours(self::DISCONNECTED_EVERY_HOURS))
            ->exists();
    }
}
