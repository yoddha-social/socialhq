<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A link that lets the owner of a network account connect it to a workspace
 * without a Social HQ login. It works once, for an hour, for one workspace and
 * one network, and an admin can cancel it before then. The row stays as the
 * record of what happened.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string|null $created_by
 * @property Platform $platform
 * @property string|null $reconnect_account_id
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $used_at
 * @property string|null $used_ip
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by
 * @property list<array{id: string, name: string}>|null $connected
 */
class ConnectInvite extends Model
{
    use MassPrunable;

    /** How long a link works. */
    public const int TTL_MINUTES = 60;

    /** Rows are deleted this long after the link stopped working (`model:prune`, daily). */
    public const int RETENTION_DAYS = 90;

    private const int TOKEN_LENGTH = 48;

    protected $table = 'shq_connect_invites';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * Networks that connect through a redirect to the network's own sign-in. The
     * others ask for a password or a bot inside Social HQ, which a guest must not do.
     *
     * @return list<Platform>
     */
    public static function platforms(): array
    {
        return [
            Platform::LinkedIn,
            Platform::X,
            Platform::TikTok,
            Platform::YouTube,
            Platform::Facebook,
            Platform::Instagram,
            Platform::InstagramFacebook,
            Platform::Threads,
            Platform::Pinterest,
            Platform::Discord,
            Platform::GoogleBusiness,
        ];
    }

    /**
     * The platform a connection of this channel's network starts from, or null
     * when the network cannot be connected by a guest.
     */
    public static function entryFor(Platform $platform): ?Platform
    {
        $entry = $platform === Platform::LinkedInPage ? Platform::LinkedIn : $platform;

        return in_array($entry, self::platforms(), true) && $entry->canStartConnect() ? $entry : null;
    }

    /**
     * A new link. The token is returned here and nowhere else.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Workspace $workspace, Platform $entry, User $by, ?SocialAccount $reconnect = null): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $invite = self::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $by->id,
            'platform' => $entry,
            'reconnect_account_id' => $reconnect?->id,
            'token_hash' => self::hash($token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return [$invite, $token];
    }

    public static function findOpenByToken(string $token): ?self
    {
        return self::query()->open()->where('token_hash', self::hash($token))->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Links that still work: not used and not past their hour.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * The link stops working at once, as a use would stop it (every check
     * reads `used_at`), and records who cancelled it.
     */
    public function cancel(User $by): void
    {
        $this->update([
            'used_at' => now(),
            'cancelled_at' => now(),
            'cancelled_by' => $by->id,
        ]);
    }

    /**
     * The workspace's links that still work, soonest to stop first: the
     * channel page lists them with a Cancel button.
     *
     * @return list<array{id: int, network: string, reconnect: string|null, created_by: string|null, created_at: string, expires_at: string}>
     */
    public static function openForPage(Workspace $workspace): array
    {
        return self::query()
            ->open()
            ->where('workspace_id', $workspace->id)
            ->with('creator:id,name')
            ->orderBy('expires_at')
            ->get()
            ->map(fn (self $invite): array => [
                'id' => $invite->id,
                'network' => $invite->channelName(),
                'reconnect' => $invite->reconnect_account_id === null
                    ? null
                    : SocialAccount::query()->where('workspace_id', $workspace->id)->find($invite->reconnect_account_id)?->display_label,
                'created_by' => $invite->creator?->name,
                'created_at' => $invite->created_at->toIso8601ZuluString(),
                'expires_at' => $invite->expires_at->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }

    /**
     * The network's name in route names: `app.social.<slug>.connect`.
     */
    public function networkSlug(): string
    {
        return Str::replace('_', '-', $this->platform->value);
    }

    /**
     * What gets connected, as a guest reads it: "Facebook Page", "Instagram", "YouTube".
     */
    public function channelName(): string
    {
        return match ($this->platform) {
            Platform::InstagramFacebook => Platform::Instagram->label(),
            Platform::YouTube => 'YouTube',
            default => $this->platform->label(),
        };
    }

    /**
     * Where the guest signs in: Instagram through Facebook signs in on Facebook,
     * YouTube and Google Business on Google.
     */
    public function signInName(): string
    {
        return match ($this->platform) {
            Platform::Facebook, Platform::InstagramFacebook => 'Facebook',
            Platform::YouTube, Platform::GoogleBusiness => 'Google',
            default => $this->platform->label(),
        };
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'expires_at' => 'datetime',
            'opened_at' => 'datetime',
            'started_at' => 'datetime',
            'used_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'connected' => 'array',
        ];
    }
}
