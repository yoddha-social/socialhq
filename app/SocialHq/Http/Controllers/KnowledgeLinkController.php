<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\StoreKnowledgeLinkRequest;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Documents\DocumentsPage;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentType;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\Sources\ReadLinkedDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google Docs, Sheets and Slides links as knowledge documents (link-shared
 * files only): workspace admins add them and read them again; the text is
 * read by a queued job (ReadLinkedDocument). Renaming, switching off and
 * deleting go through KnowledgeDocumentController like any document.
 */
class KnowledgeLinkController extends Controller
{
    public function store(StoreKnowledgeLinkRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $link = $request->link();

        // Count, then create, like uploads: two admins at once may pass the limit by one.
        if (! DocumentsPage::hasRoom($workspace)) {
            throw ValidationException::withMessages(['url' => __('socialhq_knowledge.documents.errors.too_many', ['count' => DocumentsPage::limit()])]);
        }

        if (KnowledgeDocument::query()->where('workspace_id', $workspace->id)->where('source_url', $link->canonicalUrl())->exists()) {
            throw ValidationException::withMessages(['url' => __('socialhq_knowledge.links.errors.already_added')]);
        }

        $title = Str::limit(trim((string) $request->validated('title')), 120, '');
        $fallback = __("socialhq_knowledge.links.default_titles.{$link->source->value}");

        $document = KnowledgeDocument::query()->create([
            'workspace_id' => $workspace->id,
            'title' => $title ?: $fallback,
            'auto_title' => $title === '',
            'file_name' => ($title ?: $fallback).'.txt',
            'mime' => DocumentType::Text->mime(),
            'size' => 0,
            'path' => '',
            'status' => DocumentStatus::Processing,
            'source' => $link->source,
            'source_url' => $link->canonicalUrl(),
            'use_in_ai' => (bool) $request->validated('use_in_ai', true),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        ReadLinkedDocument::dispatch($document->id);

        return $this->saved(__('socialhq_knowledge.links.flash.added', ['title' => $document->title]));
    }

    /**
     * "Read again": the file's current text replaces what was read before.
     */
    public function refresh(Request $request, int $document): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $found = KnowledgeDocument::query()->where('workspace_id', $workspace->id)->findOrFail($document);

        abort_unless($found->source->isGoogle(), Response::HTTP_NOT_FOUND);

        if ($found->status !== DocumentStatus::Processing) {
            $found->forceFill(['status' => DocumentStatus::Processing, 'failure' => null, 'updated_by' => $request->user()->id])->save();
            ReadLinkedDocument::dispatch($found->id);
        }

        return $this->saved(__('socialhq_knowledge.links.flash.reading', ['title' => $found->title]));
    }

    private function workspace(Request $request): Workspace
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless(BrandKnowledge::canEdit($user, $workspace), Response::HTTP_FORBIDDEN);

        return $workspace;
    }

    private function saved(string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
