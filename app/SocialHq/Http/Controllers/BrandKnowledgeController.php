<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\StoreKnowledgeEntryRequest;
use App\SocialHq\Http\Requests\UpdateBrandProfileRequest;
use App\SocialHq\Http\Requests\UpdateKnowledgeEntryRequest;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\KnowledgeKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The current workspace's brand profile and knowledge entries. Every member
 * who can post reads them; workspace admins change them.
 */
class BrandKnowledgeController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request, edit: false);
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();
        $prompt = BrandKnowledge::assemble($workspace);

        return Inertia::render('socialhq/BrandKnowledge', [
            'profile' => collect(BrandProfile::FIELDS)->mapWithKeys(fn (string $field): array => [$field => (string) $profile?->{$field}]),
            'entries' => KnowledgeEntry::query()
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
                    'fit' => $prompt->fitOf($entry)->value,
                    'updated_at' => $entry->updated_at?->toIso8601String(),
                ]),
            'usage' => [
                'used' => $prompt->used,
                'budget' => $prompt->budget,
                'profileTrimmed' => $prompt->profileTrimmed,
            ],
            'kinds' => array_map(fn (KnowledgeKind $kind): string => $kind->value, KnowledgeKind::cases()),
            'canEdit' => BrandKnowledge::canEdit($request->user(), $workspace),
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

    private function workspace(Request $request, bool $edit): Workspace
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, HttpResponse::HTTP_NOT_FOUND);
        abort_unless($edit ? BrandKnowledge::canEdit($user, $workspace) : BrandKnowledge::canView($user, $workspace), HttpResponse::HTTP_FORBIDDEN);

        return $workspace;
    }

    /**
     * An entry of the current workspace, which the user may change.
     */
    private function entry(Request $request, int $id): KnowledgeEntry
    {
        $workspace = $this->workspace($request, edit: true);

        return KnowledgeEntry::query()->where('workspace_id', $workspace->id)->findOrFail($id);
    }

    private function saved(string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
