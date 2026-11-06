<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\StoreKnowledgeDocumentRequest;
use App\SocialHq\Http\Requests\UpdateKnowledgeDocumentRequest;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Documents\DocumentsPage;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\ExtractKnowledgeDocument;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Brand knowledge documents of the current workspace: workspace admins
 * upload, rename, switch off and delete them; everyone who reads brand
 * knowledge may download them. The text is read by a queued job.
 */
class KnowledgeDocumentController extends Controller
{
    public function store(StoreKnowledgeDocumentRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        // Count, then create, like entries: two admins at once may pass the limit by one.
        if (KnowledgeDocument::query()->where('workspace_id', $workspace->id)->count() >= DocumentsPage::limit()) {
            throw ValidationException::withMessages(['file' => __('socialhq_knowledge.documents.errors.too_many', ['count' => DocumentsPage::limit()])]);
        }

        $file = $request->file('file');
        $type = $request->documentType();
        $fileName = Str::limit(self::plainName($file->getClientOriginalName()), 250, '') ?: "document.{$type->value}";
        $path = $file->storeAs(DocumentStorage::directory((string) $workspace->id), Str::uuid()->toString().".{$type->value}", ['disk' => DocumentStorage::DISK]);

        abort_if($path === false, Response::HTTP_INTERNAL_SERVER_ERROR);

        $title = trim((string) $request->validated('title')) ?: pathinfo($fileName, PATHINFO_FILENAME);

        $document = KnowledgeDocument::query()->create([
            'workspace_id' => $workspace->id,
            'title' => Str::limit($title, 120, '') ?: $fileName,
            'file_name' => $fileName,
            'mime' => $type->mime(),
            'size' => (int) $file->getSize(),
            'path' => $path,
            'status' => DocumentStatus::Processing,
            'use_in_ai' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        ExtractKnowledgeDocument::dispatch($document->id);

        return $this->saved(__('socialhq_knowledge.documents.flash.uploaded', ['title' => $document->title]));
    }

    public function show(Request $request, int $document): StreamedResponse
    {
        $workspace = $this->workspace($request, edit: false);
        $found = KnowledgeDocument::query()->where('workspace_id', $workspace->id)->findOrFail($document);

        abort_unless(DocumentStorage::disk()->exists($found->path), Response::HTTP_NOT_FOUND);

        return DocumentStorage::disk()->download($found->path, $found->file_name, [
            'Content-Type' => $found->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function update(UpdateKnowledgeDocumentRequest $request, int $document): RedirectResponse
    {
        $found = $this->document($request, $document);

        $found->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return $this->saved(__('socialhq_knowledge.documents.flash.saved'));
    }

    public function destroy(Request $request, int $document): RedirectResponse
    {
        $this->document($request, $document)->delete();

        return $this->saved(__('socialhq_knowledge.documents.flash.deleted'));
    }

    private function workspace(Request $request, bool $edit): Workspace
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless($edit ? BrandKnowledge::canEdit($user, $workspace) : BrandKnowledge::canView($user, $workspace), Response::HTTP_FORBIDDEN);

        return $workspace;
    }

    /**
     * A document of the current workspace, which the user may change.
     */
    private function document(Request $request, int $id): KnowledgeDocument
    {
        $workspace = $this->workspace($request, edit: true);

        return KnowledgeDocument::query()->where('workspace_id', $workspace->id)->findOrFail($id);
    }

    /**
     * The uploaded name without folders or control characters.
     */
    private static function plainName(string $name): string
    {
        return trim((string) preg_replace('/\p{Cc}/u', '', basename(str_replace('\\', '/', $name))));
    }

    private function saved(string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
