<?php

declare(strict_types=1);

namespace App\SocialHq\Production\Ai;

use App\Models\User;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Look\BrandLook;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefKind;
use Illuminate\Support\Str;

/**
 * "Draft brief with AI": asks BriefWriter for the brief's text. Nothing is
 * saved; the page fills the empty fields and asks before replacing typed
 * text. Returns null when the answer is empty.
 */
final class DraftBrief
{
    /**
     * @return array{on_screen_text: string, direction: string, references: string}|null
     */
    public static function execute(Brief $brief, User $user): ?array
    {
        $item = $brief->item()->with('event')->firstOrFail();
        $version = ItemVersion::query()->where('item_id', $item->id)->orderByDesc('number')->first();

        $context = array_filter([
            'title' => $item->title,
            'date' => $item->scheduled_on->toDateString(),
            'format' => $item->content_type?->label(),
            'creative' => $item->creative->value,
            'pillar' => $item->pillar,
            'objective' => $item->objective,
            'angle' => $item->angle,
            'event' => $item->event?->name,
            'copy' => $version === null ? null : array_filter([
                'approved' => $item->copy_status === CopyStatus::Approved,
                'caption' => $version->caption,
                'script' => $version->script,
            ], fn (mixed $value): bool => $value !== null),
            'brief' => array_filter([
                'kind' => $brief->kind->value,
                'format_notes' => $brief->format_notes,
                'on_screen_text' => $brief->on_screen_text,
                'direction' => $brief->direction,
                'references' => $brief->references,
            ], fn (mixed $value): bool => filled($value)),
        ], fn (mixed $value): bool => filled($value));

        // The brief's workspace decides whose AI it uses, whatever workspace the user has open.
        $response = AiTenant::run($brief->workspace, fn () => (new BriefWriter(
            language: $user->locale->promptLanguage(),
            context: $context,
            // What the brief is about: the item, and the caption it illustrates.
            brandKnowledge: BrandKnowledge::forPrompt($brief->workspace, collect([$item->title, $item->angle, $version?->caption])->filter()->implode("\n")),
            isVideo: $brief->kind === BriefKind::Video,
            brandLook: BrandLook::promptFor($brief->workspace),
        ))->prompt('Draft the brief for this post.'));

        $draft = [
            'on_screen_text' => Str::substr(trim((string) data_get($response, 'on_screen_text')), 0, 5000),
            'direction' => Str::substr(trim((string) data_get($response, 'direction')), 0, 10000),
            'references' => Str::substr(trim((string) data_get($response, 'references')), 0, 5000),
        ];

        return $draft['on_screen_text'] === '' && $draft['direction'] === '' ? null : $draft;
    }
}
