<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\KnowledgeKind;
use App\SocialHq\Knowledge\Look\BrandLook;

/**
 * Turns WebsiteReader's answer into suggestions an admin decides on one by
 * one, checked in code first: empty or unchanged profile fields, entries
 * the brand already has (by title), colours that weren't in the site's
 * markup or are already in the brand look, and anything too long, are
 * left out. Nothing is saved to brand knowledge here (WebsiteReview does
 * that on Accept).
 *
 * A suggestion: key, type (profile, entry, colour, font, style, document),
 * status (open, accepted, skipped) and its fields: `field` and `value` for
 * profile, font and style; `kind`, `title` and `body` for an entry; `name`
 * and `hex` for a colour; `pages` for the document.
 */
final class WebsiteSuggestions
{
    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const SKIPPED = 'skipped';

    /** Longest value per profile field, as the profile form allows. */
    public const PROFILE_LIMITS = ['about' => 2000, 'audience' => 1000, 'voice' => 1000];

    /**
     * @param  list<string>  $colourCandidates  Colours found in the site's markup
     * @param  int  $pages  Pages that can be kept as the "Website" document
     * @return list<array<string, mixed>>
     */
    public static function build(mixed $response, Workspace $workspace, array $colourCandidates, int $pages): array
    {
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();
        $look = BrandLook::of($workspace);
        $suggestions = [];

        foreach (self::PROFILE_LIMITS as $field => $limit) {
            $value = self::text(data_get($response, "profile.{$field}"), $limit);

            if ($value !== '' && $value !== trim((string) $profile?->{$field})) {
                $suggestions[] = ['key' => "profile-{$field}", 'type' => 'profile', 'field' => $field, 'value' => $value];
            }
        }

        $titles = KnowledgeEntry::query()->where('workspace_id', $workspace->id)->pluck('title')->map(self::titleKey(...))->flip()->all();
        $kinds = array_map(fn (KnowledgeKind $kind): string => $kind->value, WebsiteReader::KINDS);
        $entries = 0;

        foreach ((array) data_get($response, 'entries', []) as $entry) {
            $title = self::text(data_get($entry, 'title'), 120);
            $body = self::text(data_get($entry, 'body'), 4000);
            $kind = (string) data_get($entry, 'kind');

            if ($title === '' || $body === '' || isset($titles[self::titleKey($title)]) || $entries >= WebsiteReader::MAX_ENTRIES) {
                continue;
            }

            $titles[self::titleKey($title)] = true;
            $entries++;
            $suggestions[] = [
                'key' => "entry-{$entries}",
                'type' => 'entry',
                'kind' => in_array($kind, $kinds, true) ? $kind : KnowledgeKind::Other->value,
                'title' => $title,
                'body' => $body,
            ];
        }

        $taken = array_column($look?->colourList() ?? [], 'hex');
        $colours = 0;

        foreach ((array) data_get($response, 'theme.colours', []) as $colour) {
            $hex = strtolower(trim((string) data_get($colour, 'hex')));

            // Only colours the site's markup really uses; the AI never picks a hex value itself.
            if (! in_array($hex, $colourCandidates, true) || in_array($hex, $taken, true) || $colours >= BrandLook::MAX_COLOURS) {
                continue;
            }

            $taken[] = $hex;
            $colours++;
            $suggestions[] = ['key' => "colour-{$colours}", 'type' => 'colour', 'name' => self::text(data_get($colour, 'name'), 40), 'hex' => $hex];
        }

        foreach (['heading_font' => 80, 'body_font' => 80] as $field => $limit) {
            $value = self::text(data_get($response, "theme.{$field}"), $limit);

            if ($value !== '' && $value !== trim((string) $look?->{$field})) {
                $suggestions[] = ['key' => "font-{$field}", 'type' => 'font', 'field' => $field, 'value' => $value];
            }
        }

        $style = self::text(data_get($response, 'theme.style_notes'), 2000);

        if ($style !== '' && $style !== trim((string) $look?->style_notes)) {
            $suggestions[] = ['key' => 'style', 'type' => 'style', 'field' => 'style_notes', 'value' => $style];
        }

        if ($pages > 0) {
            $suggestions[] = ['key' => 'document', 'type' => 'document', 'pages' => $pages];
        }

        return array_map(fn (array $suggestion): array => [...$suggestion, 'status' => self::OPEN], $suggestions);
    }

    private static function text(mixed $value, int $limit): string
    {
        $text = is_string($value) ? trim($value) : '';

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }

    private static function titleKey(string $title): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
    }
}
