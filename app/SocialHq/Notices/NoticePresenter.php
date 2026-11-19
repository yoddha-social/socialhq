<?php

declare(strict_types=1);

namespace App\SocialHq\Notices;

use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

/**
 * Turns stored notices into what the bell shows: a title and a line in the
 * reader's language, and the buttons that lead to what the notice is about.
 * A post or channel deleted since then simply has no button.
 */
class NoticePresenter
{
    /**
     * @param  Collection<int, Notice>  $notices
     * @return list<array<string, mixed>>
     */
    public static function list(Collection $notices): array
    {
        $posts = Post::query()->whereIn('id', $notices->pluck('post_id')->filter()->unique())->pluck('id')->flip();
        $channels = SocialAccount::query()->whereIn('id', $notices->pluck('social_account_id')->filter()->unique())->pluck('id')->flip();

        return $notices
            ->map(fn (Notice $notice): array => self::present($notice, $posts->has((string) $notice->post_id), $channels->has((string) $notice->social_account_id)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Notice $notice, bool $hasPost, bool $hasChannel): array
    {
        $kind = $notice->kind;
        $data = $notice->data;
        $replace = [
            'channel' => (string) data_get($data, 'channel', ''),
            'actor' => (string) data_get($data, 'actor', ''),
            'count' => (string) data_get($data, 'count', 0),
        ];

        return [
            'id' => $notice->id,
            'kind' => $kind->value,
            'tone' => $kind->tone(),
            'title' => $kind === NoticeKind::PostsAtRisk
                ? trans_choice('socialhq.notices.kinds.posts_at_risk.title', (int) data_get($data, 'count', 0), $replace)
                : __("socialhq.notices.kinds.{$kind->value}.title", $replace),
            'body' => self::body($notice),
            'created_at' => $notice->created_at->toIso8601ZuluString(),
            'read' => $notice->read_at !== null,
            'actions' => self::actions($notice, $hasPost, $hasChannel),
        ];
    }

    private static function body(Notice $notice): string
    {
        $data = $notice->data;

        return (string) match ($notice->kind) {
            NoticeKind::PostFailed, NoticeKind::PostRejected => data_get($data, 'reason') ?: data_get($data, 'excerpt', ''),
            NoticeKind::PostNote => data_get($data, 'note', ''),
            NoticeKind::ChannelDisconnected, NoticeKind::PostsAtRisk, NoticeKind::ChannelConnected => __("socialhq.notices.kinds.{$notice->kind->value}.body"),
            default => data_get($data, 'excerpt', ''),
        };
    }

    /**
     * @return list<array{label: string, href: string, external: bool}>
     */
    private static function actions(Notice $notice, bool $hasPost, bool $hasChannel): array
    {
        $openPost = $hasPost ? [self::action('open_post', route('app.posts.edit', $notice->post_id))] : [];
        $reconnect = $hasChannel ? [self::action('reconnect', route('app.channels.publish', $notice->social_account_id))] : [];

        return match ($notice->kind) {
            NoticeKind::PostPublished => filled(data_get($notice->data, 'url'))
                ? [self::action('view_post', (string) data_get($notice->data, 'url'), true)]
                : $openPost,
            NoticeKind::PostFailed => [...(data_get($notice->data, 'reconnect') ? $reconnect : []), ...$openPost],
            NoticeKind::ChannelDisconnected => $reconnect,
            NoticeKind::ChannelConnected => $hasChannel ? [self::action('open_channel', route('app.channels.settings', $notice->social_account_id))] : [],
            NoticeKind::PostsAtRisk => [self::action('review', route('app.workspace.channels'))],
            NoticeKind::ApprovalRequested => $hasPost ? [self::action('review', route('socialhq.inbox'))] : [],
            NoticeKind::PostApproved, NoticeKind::PostRejected, NoticeKind::PostNote => $openPost,
        };
    }

    /**
     * @return array{label: string, href: string, external: bool}
     */
    private static function action(string $key, string $href, bool $external = false): array
    {
        return ['label' => __("socialhq.notices.actions.{$key}"), 'href' => $href, 'external' => $external];
    }
}
