<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\User;
use App\Models\Workspace;

/**
 * What the AI is told about a workspace's brand: its profile, then the
 * entries marked for AI, newest first, until the character budget is spent.
 */
final class BrandKnowledge
{
    private const PROFILE_LABELS = [
        'about' => 'What the brand is',
        'audience' => 'Audience',
        'voice' => 'Voice and tone',
        'words_to_use' => 'Words and phrases to use',
        'words_to_avoid' => 'Words and phrases to avoid',
    ];

    public static function canEdit(User $user, Workspace $workspace): bool
    {
        return $user->can('update', $workspace);
    }

    public static function canView(User $user, Workspace $workspace): bool
    {
        return $user->can('createPost', $workspace);
    }

    public static function forPrompt(?Workspace $workspace): string
    {
        if ($workspace === null) {
            return '';
        }

        $budget = max(500, (int) config('socialhq.knowledge.prompt_characters', 6000));
        $sections = [];
        $used = 0;

        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();

        foreach (self::PROFILE_LABELS as $field => $label) {
            $value = trim((string) $profile?->{$field});

            if ($value !== '') {
                $sections[] = "{$label}:\n{$value}";
            }
        }

        $used = mb_strlen(implode("\n\n", $sections));

        $entries = KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->usedInAi()
            ->latest('updated_at')
            ->latest('id')
            ->get(['kind', 'title', 'body']);

        $facts = [];

        foreach ($entries as $entry) {
            $fact = "- {$entry->kind->promptLabel()}: {$entry->title}\n  ".str_replace("\n", "\n  ", trim($entry->body));
            $length = mb_strlen($fact) + 1;

            if ($used + $length > $budget) {
                continue;
            }

            $facts[] = $fact;
            $used += $length;
        }

        if ($facts !== []) {
            $sections[] = "What to know:\n".implode("\n", $facts);
        }

        return implode("\n\n", $sections);
    }
}
