<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An item's copy and its review: versions are only ever added, and every
 * step (sent for review, changes requested, approved, reopened) is written
 * to the item's review thread.
 */
final class ItemCopy
{
    /**
     * Save the copy as the item's next version. Any new version goes back to
     * draft; a new version of approved copy is noted in the thread.
     *
     * @param  array{caption: string, hashtags?: ?string, script?: ?string, notes?: ?string}  $copy
     */
    public static function saveVersion(PlanItem $item, User $user, array $copy, ItemSource $source = ItemSource::Manual): ItemVersion
    {
        return DB::transaction(function () use ($item, $user, $copy, $source): ItemVersion {
            $locked = PlanItem::query()->lockForUpdate()->findOrFail($item->id);
            $wasApproved = $locked->copy_status === CopyStatus::Approved;

            $version = ItemVersion::query()->create([
                'item_id' => $locked->id,
                'number' => (int) ItemVersion::query()->where('item_id', $locked->id)->max('number') + 1,
                'caption' => $copy['caption'],
                'hashtags' => self::blankToNull($copy['hashtags'] ?? null),
                'script' => self::blankToNull($copy['script'] ?? null),
                'notes' => self::blankToNull($copy['notes'] ?? null),
                'source' => $source,
                'created_by' => $user->id,
            ]);

            $locked->update(['copy_status' => CopyStatus::Draft]);

            if ($wasApproved) {
                self::note($locked, $user, ReviewKind::Reopened, $version);
            }

            $item->setRawAttributes($locked->getAttributes(), true);

            return $version;
        });
    }

    public static function submit(PlanItem $item, User $user): void
    {
        self::transition($item, [CopyStatus::Draft, CopyStatus::ChangesRequested], CopyStatus::InReview, 'cannot_submit', function (PlanItem $locked, ItemVersion $version) use ($user): void {
            self::note($locked, $user, ReviewKind::Submitted, $version);
        });
    }

    public static function approve(PlanItem $item, User $user, ?string $note = null): void
    {
        self::transition($item, [CopyStatus::InReview], CopyStatus::Approved, 'cannot_approve', function (PlanItem $locked, ItemVersion $version) use ($user, $note): void {
            self::note($locked, $user, ReviewKind::Approved, $version, $note);
        });
    }

    public static function requestChanges(PlanItem $item, User $user, string $note): void
    {
        self::transition($item, [CopyStatus::InReview], CopyStatus::ChangesRequested, 'cannot_request_changes', function (PlanItem $locked, ItemVersion $version) use ($user, $note): void {
            self::note($locked, $user, ReviewKind::ChangesRequested, $version, $note);
        });
    }

    /**
     * Change requests on the copy since its latest version was saved: what a
     * rewrite has to address.
     *
     * @return list<string>
     */
    public static function openChangeRequests(PlanItem $item): array
    {
        $latest = $item->latestVersion;

        return ItemReview::query()
            ->where('item_id', $item->id)
            ->whereNull('brief_id')
            ->where('kind', ReviewKind::ChangesRequested)
            ->when($latest !== null, fn ($query) => $query->where('version_id', $latest->id))
            ->orderBy('id')
            ->pluck('body')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<CopyStatus>  $from
     * @param  callable(PlanItem, ItemVersion): void  $record
     */
    private static function transition(PlanItem $item, array $from, CopyStatus $to, string $error, callable $record): void
    {
        DB::transaction(function () use ($item, $from, $to, $error, $record): void {
            $locked = PlanItem::query()->lockForUpdate()->findOrFail($item->id);
            $version = ItemVersion::query()->where('item_id', $locked->id)->orderByDesc('number')->first();

            if ($version === null || ! in_array($locked->copy_status, $from, true)) {
                throw ValidationException::withMessages(['copy' => __("socialhq_production.item.copy.errors.{$error}")]);
            }

            $locked->update(['copy_status' => $to]);
            $record($locked, $version);

            $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private static function note(PlanItem $item, User $user, ReviewKind $kind, ?ItemVersion $version, ?string $body = null): void
    {
        ItemReview::query()->create([
            'item_id' => $item->id,
            'version_id' => $version?->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'body' => self::blankToNull($body),
        ]);
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
