<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Models\SocialAccount;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

/**
 * A guest's way through a connect link: which link their browser session is
 * using, and what it connected once they finished. Kept in the session only;
 * the link itself is checked again on every request.
 */
final class ConnectInviteFlow
{
    public const string SESSION_KEY = 'shq_connect_invite';

    public const string DONE_KEY = 'shq_connect_invite_done';

    /**
     * The guest pressed "Continue" on a link that still works.
     */
    public static function begin(ConnectInvite $invite): void
    {
        PendingConnection::forget();
        Session::forget(self::DONE_KEY);
        Session::put(self::SESSION_KEY, $invite->id);

        if ($invite->started_at === null) {
            $invite->update(['started_at' => now()]);
        }
    }

    public static function isActive(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    /**
     * The link this session is using, or null once it is used or past its hour.
     */
    public static function current(): ?ConnectInvite
    {
        $id = Session::get(self::SESSION_KEY);
        $invite = is_int($id) ? ConnectInvite::query()->open()->find($id) : null;

        if ($invite === null) {
            self::forget();
        }

        return $invite;
    }

    public static function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * The pending connection of this link's network and workspace, if the guest
     * has come back from the network.
     */
    public static function pending(ConnectInvite $invite): ?PendingConnection
    {
        $pending = PendingConnection::current();

        return $pending?->platform() === $invite->platform && $pending->workspaceId() === $invite->workspace_id
            ? $pending
            : null;
    }

    /**
     * The link did its job: it stops working, records what it connected and
     * tells the admin who sent it.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     */
    public static function complete(ConnectInvite $invite, Collection $accounts, ?string $ip): void
    {
        $connected = $accounts->map(fn (SocialAccount $account): array => [
            'id' => $account->id,
            'name' => $account->display_label,
        ])->values()->all();

        $invite->update([
            'used_at' => now(),
            'used_ip' => $ip,
            'connected' => $connected,
        ]);

        self::forget();
        PendingConnection::forget();
        Session::put(self::DONE_KEY, [
            'workspace' => $invite->workspace?->name,
            'platform' => $invite->platform->value,
            'channels' => array_column($connected, 'name'),
        ]);
    }

    /**
     * What the finished page shows, once.
     *
     * @return array{workspace: ?string, platform: string, channels: list<string>}|null
     */
    public static function done(): ?array
    {
        $done = Session::get(self::DONE_KEY);

        return is_array($done) ? $done : null;
    }
}
