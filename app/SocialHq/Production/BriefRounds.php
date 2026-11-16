<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Models\User;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ReviewKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The creative work on a brief, in review rounds: uploads go into the
 * current round until it is decided (changes requested, approved, or an
 * approved creative reopened), then the next upload starts a new round.
 * Every step is written to the item's review thread.
 */
final class BriefRounds
{
    /**
     * The round the next upload goes into.
     */
    public static function uploadRound(Brief $brief): int
    {
        $current = $brief->currentRound();

        if ($current === 0) {
            return 1;
        }

        return self::isDecided($brief, $current) ? $current + 1 : $current;
    }

    /**
     * Uploads wait while a round is in review or the creative is approved.
     */
    public static function acceptsUploads(Brief $brief): bool
    {
        return ! in_array($brief->stage, [BriefStage::InReview, BriefStage::Approved], true);
    }

    public static function upload(Brief $brief, User $user, UploadedFile $file, string $mimeType, ?string $note): BriefFile
    {
        if (! self::acceptsUploads($brief)) {
            throw ValidationException::withMessages(['file' => __('socialhq_production.creative.errors.not_accepting')]);
        }

        // The file is written before the row lock is taken (a slow upload never holds it) and removed if the row isn't saved.
        $path = BriefStorage::disk()->putFileAs(
            BriefStorage::directory($brief),
            $file,
            Str::uuid()->toString().'.'.BriefStorage::extensionFor($mimeType),
        );

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('socialhq_production.creative.upload_failed', ['name' => $file->getClientOriginalName()])]);
        }

        try {
            return DB::transaction(function () use ($brief, $user, $file, $mimeType, $note, $path): BriefFile {
                $locked = Brief::query()->lockForUpdate()->findOrFail($brief->id);

                if (! self::acceptsUploads($locked)) {
                    throw ValidationException::withMessages(['file' => __('socialhq_production.creative.errors.not_accepting')]);
                }

                $stored = BriefFile::query()->create([
                    'brief_id' => $locked->id,
                    'round' => self::uploadRound($locked),
                    'path' => $path,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'mime_type' => $mimeType,
                    'size' => (int) $file->getSize(),
                    'uploaded_by' => $user->id,
                    'note' => filled($note) ? $note : null,
                ]);

                if (in_array($locked->stage, [BriefStage::Todo, BriefStage::ChangesRequested], true)) {
                    self::moveTo($locked, BriefStage::InProgress);
                }

                $brief->setRawAttributes($locked->getAttributes(), true);

                return $stored;
            });
        } catch (Throwable $exception) {
            BriefStorage::disk()->delete($path);

            throw $exception;
        }
    }

    /**
     * Why the current round can't go to review, or null when it can: it
     * needs files, and a round that was already decided is done.
     */
    public static function submitBlocker(Brief $brief): ?string
    {
        $round = $brief->currentRound();

        if ($round === 0 || self::isDecided($brief, $round)) {
            return __('socialhq_production.creative.errors.cannot_submit');
        }

        return null;
    }

    public static function submit(Brief $brief, User $user): void
    {
        DB::transaction(function () use ($brief, $user): void {
            $locked = Brief::query()->lockForUpdate()->findOrFail($brief->id);
            if (! self::acceptsUploads($locked) || self::submitBlocker($locked) !== null) {
                throw ValidationException::withMessages(['creative' => __('socialhq_production.creative.errors.cannot_submit')]);
            }

            self::moveTo($locked, BriefStage::InReview);
            self::note($locked, $user, ReviewKind::Submitted, $locked->currentRound());
            $brief->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  ?int  $shownRound  the round the approver saw, refused when the brief has moved on since
     */
    public static function approve(Brief $brief, User $user, ?string $note = null, ?int $shownRound = null): void
    {
        self::decide($brief, $user, BriefStage::Approved, $note, $shownRound);
    }

    /**
     * @param  ?int  $shownRound  the round the approver saw, refused when the brief has moved on since
     */
    public static function requestChanges(Brief $brief, User $user, ?string $note, ?int $shownRound = null): void
    {
        self::decide($brief, $user, BriefStage::ChangesRequested, $note, $shownRound);
    }

    /**
     * Put the brief in a stage, at the end of that column; approving records
     * who approved it, leaving Approved clears it.
     */
    public static function moveTo(Brief $brief, BriefStage $stage, ?User $approver = null, ?int $position = null): void
    {
        if ($brief->stage === $stage && $position === null) {
            return;
        }

        $brief->forceFill([
            'stage' => $stage,
            'position' => $position ?? (int) Brief::query()
                ->where('workspace_id', $brief->workspace_id)
                ->where('stage', $stage)
                ->whereKeyNot($brief->id)
                ->max('position') + 1,
            'approved_at' => $stage === BriefStage::Approved ? ($brief->approved_at ?? now()) : null,
            'approved_by' => $stage === BriefStage::Approved ? ($brief->approved_by ?? $approver?->id) : null,
        ])->save();
    }

    public static function note(Brief $brief, User $user, ReviewKind $kind, ?int $round, ?string $body = null): void
    {
        ItemReview::query()->create([
            'item_id' => $brief->item_id,
            'brief_id' => $brief->id,
            'round' => $round,
            'user_id' => $user->id,
            'kind' => $kind,
            'body' => filled($body) ? $body : null,
        ]);
    }

    /**
     * Approve the round in review or ask for changes on it: the review
     * endpoint and the board both come here. Only a round that is in review,
     * has files and hasn't been decided yet; with $shownRound, only the
     * round the approver was looking at.
     */
    public static function decide(Brief $brief, User $user, BriefStage $to, ?string $note = null, ?int $shownRound = null): void
    {
        [$kind, $error] = match ($to) {
            BriefStage::Approved => [ReviewKind::Approved, 'cannot_approve'],
            BriefStage::ChangesRequested => [ReviewKind::ChangesRequested, 'cannot_request_changes'],
        };

        DB::transaction(function () use ($brief, $user, $to, $kind, $note, $error, $shownRound): void {
            $locked = Brief::query()->lockForUpdate()->findOrFail($brief->id);

            if ($locked->stage !== BriefStage::InReview || self::submitBlocker($locked) !== null) {
                throw ValidationException::withMessages(['creative' => __("socialhq_production.creative.errors.{$error}")]);
            }

            if ($shownRound !== null && $shownRound !== $locked->currentRound()) {
                throw ValidationException::withMessages(['creative' => __('socialhq_production.review.errors.changed')]);
            }

            self::moveTo($locked, $to, $user);
            self::note($locked, $user, $kind, $locked->currentRound(), $note);
            $brief->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Whether a round is done: changes were requested on it, it was
     * approved, or the approved creative was reopened.
     */
    public static function isDecided(Brief $brief, int $round): bool
    {
        return ItemReview::query()
            ->where('brief_id', $brief->id)
            ->where('round', $round)
            ->whereIn('kind', [ReviewKind::ChangesRequested, ReviewKind::Approved, ReviewKind::Reopened])
            ->exists();
    }

    public static function changesRequestedOn(Brief $brief, int $round): bool
    {
        return ItemReview::query()
            ->where('brief_id', $brief->id)
            ->where('round', $round)
            ->where('kind', ReviewKind::ChangesRequested)
            ->exists();
    }
}
