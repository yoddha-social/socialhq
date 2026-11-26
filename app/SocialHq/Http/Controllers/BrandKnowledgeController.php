<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Controllers\Concerns\WorksOnBrandKnowledge;
use App\SocialHq\Http\Requests\StoreKnowledgeEntryRequest;
use App\SocialHq\Http\Requests\UpdateBrandProfileRequest;
use App\SocialHq\Http\Requests\UpdateKnowledgeEntryRequest;
use App\SocialHq\Knowledge\Audience\AudiencePage;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Calendars\OldCalendars;
use App\SocialHq\Knowledge\Documents\DocumentsPage;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordCheck;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use App\SocialHq\Knowledge\Keywords\Occasion;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\KnowledgeKind;
use App\SocialHq\Knowledge\KnowledgePrompt;
use App\SocialHq\Knowledge\Look\LookPage;
use App\SocialHq\Knowledge\Website\WebsitePanel;
use App\SocialHq\Plan\PlanOptions;
use App\SocialHq\Plan\PlanPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current workspace's brand profile and knowledge entries. Every member
 * who can post reads them; workspace admins change them.
 */
class BrandKnowledgeController extends Controller
{
    use WorksOnBrandKnowledge;

    /**
     * Every prop is a closure: the page's polls reload one prop (`documents`,
     * `website`, `pastCalendars`) and skip building the others.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request, edit: false);
        $user = $request->user();
        $profile = null;
        $prompt = null;
        $channels = null;
        $loadProfile = function () use ($workspace, &$profile): ?BrandProfile {
            return $profile ??= BrandProfile::query()->where('workspace_id', $workspace->id)->first();
        };
        $loadPrompt = function () use ($workspace, &$prompt): KnowledgePrompt {
            return $prompt ??= BrandKnowledge::assemble($workspace);
        };
        $loadChannels = function () use ($workspace, &$channels): Collection {
            return $channels ??= PlanOptions::channels($workspace);
        };

        return Inertia::render('socialhq/BrandKnowledge', [
            'profile' => fn () => collect(BrandProfile::FIELDS)->mapWithKeys(fn (string $field): array => [$field => (string) $loadProfile()?->{$field}]),
            'entries' => fn () => KnowledgeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->inPromptOrder()
                ->get()
                ->map(fn (KnowledgeEntry $entry): array => [
                    'id' => $entry->id,
                    'kind' => $entry->kind->value,
                    'title' => $entry->title,
                    'body' => $entry->body,
                    'use_in_ai' => $entry->use_in_ai,
                    'pinned' => $entry->pinned,
                    'fit' => $loadPrompt()->fitOf($entry)->value,
                    'updated_at' => $entry->updated_at?->toIso8601String(),
                ]),
            'usage' => fn () => [
                'used' => $loadPrompt()->used,
                'budget' => $loadPrompt()->budget,
                'profileTrimmed' => $loadPrompt()->profileTrimmed,
                // How much of `used` the profile takes; the rest is knowledge.
                'profileUsed' => $loadPrompt()->profileUsed,
            ],
            // The plan reads these too; they are edited here and on the plan page.
            'pillars' => fn () => PlanOptions::pillars($loadProfile()),
            'cadence' => fn () => PlanOptions::cadence($loadProfile(), $loadChannels()),
            // Not 'channels': that name is the sidebar's shared prop.
            'planChannels' => fn () => $loadChannels()->map(PlanPage::channel(...))->values()->all(),
            'kinds' => fn () => array_map(fn (KnowledgeKind $kind): string => $kind->value, KnowledgeKind::cases()),
            'canEdit' => fn () => BrandKnowledge::canEdit($user, $workspace),
            'documents' => fn () => DocumentsPage::prop($workspace),
            'pastCalendars' => fn () => OldCalendars::forPage($workspace),
            'audience' => fn () => AudiencePage::prop($workspace, $user),
            'look' => fn () => LookPage::prop($workspace),
            'website' => fn () => WebsitePanel::prop($workspace),
            // The SEO keyword catalogue with its tags, the brand's dates to tag them with, and their
            // check (deferred: it reads the whole brand knowledge and recent posts).
            'keywords' => fn () => [
                'items' => array_map(fn (array $keyword): array => Arr::except($keyword, 'added_at'), BrandKeywords::catalogue($workspace)),
                'limits' => ['main' => BrandKeywords::maxMain(), 'supporting' => BrandKeywords::maxSupporting(), 'tags' => BrandKeywords::MAX_TAGS, 'per_post' => KeywordPicker::libraryPerPost()],
                'occasions' => Occasion::choices($workspace),
                'occasion_days' => ['before' => Occasion::daysBefore(), 'after' => Occasion::daysAfter()],
            ],
            // Members get the fixes that say who can act.
            'keywordCheck' => Inertia::defer(fn () => KeywordCheck::page($workspace, BrandKnowledge::canEdit($user, $workspace))),
        ]);
    }

    public function updateProfile(UpdateBrandProfileRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        BrandProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [...collect(BrandProfile::FIELDS)->mapWithKeys(fn (string $field): array => [$field => $request->validated($field)])->all(), 'updated_by' => $request->user()->id],
        );

        return $this->saved(__('socialhq.brand.flash.profile_saved'));
    }

    public function store(StoreKnowledgeEntryRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        // Count, then create: two admins adding at once may go one or two past
        // the limit. Accepted; the limit only keeps the page and the prompt small.
        if (KnowledgeEntry::query()->where('workspace_id', $workspace->id)->count() >= (int) config('socialhq.knowledge.max_entries', 300)) {
            throw ValidationException::withMessages(['limit' => __('socialhq.brand.errors.too_many')]);
        }

        KnowledgeEntry::query()->create([
            ...$request->validated(),
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return $this->saved(__('socialhq.brand.flash.entry_added'));
    }

    public function update(UpdateKnowledgeEntryRequest $request, int $entry): RedirectResponse
    {
        $found = $this->entry($request, $entry);

        $found->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return $this->saved(__('socialhq.brand.flash.entry_saved'));
    }

    public function destroy(Request $request, int $entry): RedirectResponse
    {
        $this->entry($request, $entry)->delete();

        return $this->saved(__('socialhq.brand.flash.entry_deleted'));
    }

    /**
     * An entry of the current workspace, which the user may change.
     */
    private function entry(Request $request, int $id): KnowledgeEntry
    {
        $workspace = $this->workspace($request, edit: true);

        return KnowledgeEntry::query()->where('workspace_id', $workspace->id)->findOrFail($id);
    }
}
