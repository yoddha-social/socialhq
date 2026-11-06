<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Documents\DocumentSource;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\DocumentType;
use App\SocialHq\Knowledge\Documents\ExtractKnowledgeDocument;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\KnowledgeKind;
use App\SocialHq\Knowledge\Look\BrandLook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept (as suggested, or edited) or Skip one website suggestion. Accepting
 * is the only way a suggestion reaches brand knowledge: a profile field is
 * replaced, an entry added, a colour added to the brand look, a font or the
 * style notes set, or the website's text kept as the "Website" document.
 * Each suggestion is decided once; the row is locked while deciding.
 */
final class WebsiteReview
{
    /**
     * @param  array<string, mixed>  $edited  The admin's edits (value, kind, title, body, name, hex)
     */
    public static function accept(Workspace $workspace, int $crawlId, string $key, array $edited, User $user): void
    {
        DB::transaction(function () use ($workspace, $crawlId, $key, $edited, $user): void {
            [$crawl, $index, $suggestion] = self::open($workspace, $crawlId, $key);
            $decided = self::apply($workspace, $crawl, $suggestion, $edited, $user);

            self::record($crawl, $index, [...$decided, 'status' => WebsiteSuggestions::ACCEPTED]);
        });
    }

    public static function skip(Workspace $workspace, int $crawlId, string $key): void
    {
        DB::transaction(function () use ($workspace, $crawlId, $key): void {
            [$crawl, $index, $suggestion] = self::open($workspace, $crawlId, $key);

            self::record($crawl, $index, [...$suggestion, 'status' => WebsiteSuggestions::SKIPPED]);
        });
    }

    /**
     * The crawl (locked) and its open suggestion with this key.
     *
     * @return array{0: WebsiteCrawl, 1: int, 2: array<string, mixed>}
     */
    private static function open(Workspace $workspace, int $crawlId, string $key): array
    {
        $crawl = WebsiteCrawl::query()->where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($crawlId);
        $suggestions = (array) $crawl->suggestions;
        $index = collect($suggestions)->search(fn (mixed $suggestion): bool => data_get($suggestion, 'key') === $key);

        abort_if($index === false || ! $crawl->status->hasSuggestions(), Response::HTTP_NOT_FOUND);

        if (data_get($suggestions[$index], 'status') !== WebsiteSuggestions::OPEN) {
            throw ValidationException::withMessages(['suggestion' => __('socialhq_knowledge.website.errors.decided')]);
        }

        return [$crawl, (int) $index, (array) $suggestions[$index]];
    }

    /**
     * @param  array<string, mixed>  $decided
     */
    private static function record(WebsiteCrawl $crawl, int $index, array $decided): void
    {
        $suggestions = (array) $crawl->suggestions;
        $suggestions[$index] = $decided;
        $crawl->update(['suggestions' => $suggestions]);
    }

    /**
     * Saves the suggestion and returns it as saved (with the admin's edits).
     *
     * @param  array<string, mixed>  $suggestion
     * @param  array<string, mixed>  $edited
     * @return array<string, mixed>
     */
    private static function apply(Workspace $workspace, WebsiteCrawl $crawl, array $suggestion, array $edited, User $user): array
    {
        $pick = fn (string $field): string => trim((string) data_get($edited, $field, data_get($suggestion, $field, '')));

        switch (data_get($suggestion, 'type')) {
            case 'profile':
                $field = (string) data_get($suggestion, 'field');
                $value = self::required($pick('value'), 'value');
                BrandProfile::query()->updateOrCreate(['workspace_id' => $workspace->id], [$field => $value, 'updated_by' => $user->id]);

                return [...$suggestion, 'value' => $value];

            case 'entry':
                if (KnowledgeEntry::query()->where('workspace_id', $workspace->id)->count() >= (int) config('socialhq.knowledge.max_entries', 300)) {
                    throw ValidationException::withMessages(['suggestion' => __('socialhq.brand.errors.too_many')]);
                }

                $entry = [
                    'kind' => KnowledgeKind::tryFrom($pick('kind')) ?? KnowledgeKind::Other,
                    'title' => self::required($pick('title'), 'title'),
                    'body' => self::required($pick('body'), 'body'),
                ];
                KnowledgeEntry::query()->create([...$entry, 'workspace_id' => $workspace->id, 'use_in_ai' => true, 'pinned' => false, 'created_by' => $user->id, 'updated_by' => $user->id]);

                return [...$suggestion, ...$entry, 'kind' => $entry['kind']->value];

            case 'colour':
                $look = BrandLook::query()->lockForUpdate()->firstOrNew(['workspace_id' => $workspace->id]);
                $colours = $look->colourList();
                $colour = ['name' => $pick('name'), 'hex' => strtolower(self::required($pick('hex'), 'hex'))];

                if (! in_array($colour['hex'], array_column($colours, 'hex'), true)) {
                    if (count($colours) >= BrandLook::MAX_COLOURS) {
                        throw ValidationException::withMessages(['suggestion' => __('socialhq_knowledge.look.errors.too_many_colours', ['count' => BrandLook::MAX_COLOURS])]);
                    }

                    $look->fill(['colours' => [...$colours, $colour], 'updated_by' => $user->id])->save();
                }

                return [...$suggestion, ...$colour];

            case 'font':
            case 'style':
                $value = self::required($pick('value'), 'value');
                BrandLook::query()->updateOrCreate(['workspace_id' => $workspace->id], [(string) data_get($suggestion, 'field') => $value, 'updated_by' => $user->id]);

                return [...$suggestion, 'value' => $value];

            case 'document':
                self::keepDocument($workspace, $crawl, $user);

                return $suggestion;
        }

        abort(Response::HTTP_NOT_FOUND);
    }

    private static function required(string $value, string $field): string
    {
        if ($value === '') {
            throw ValidationException::withMessages([$field => __('socialhq_knowledge.website.errors.empty')]);
        }

        return $value;
    }

    /**
     * The website's pages as one document ("Website"), for search: created,
     * or its text replaced. It doesn't count toward the documents limit.
     */
    private static function keepDocument(Workspace $workspace, WebsiteCrawl $crawl, User $user): void
    {
        $pages = CrawledPage::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get(['url', 'title', 'text'])
            ->map(fn (CrawledPage $page): array => ['url' => (string) $page->url, 'title' => (string) $page->title, 'text' => (string) $page->text])
            ->all();
        $text = WebsiteText::document(WebsiteText::deduplicate($pages));

        if ($text === '') {
            throw ValidationException::withMessages(['suggestion' => __('socialhq_knowledge.website.errors.pages_gone')]);
        }

        $document = KnowledgeDocument::query()->lockForUpdate()->where('workspace_id', $workspace->id)->where('source', DocumentSource::Website->value)->first()
            ?? new KnowledgeDocument(['workspace_id' => $workspace->id, 'source' => DocumentSource::Website, 'use_in_ai' => true, 'created_by' => $user->id]);
        $previous = $document->path;
        $path = DocumentStorage::directory((string) $workspace->id).'/'.Str::uuid()->toString().'.txt';
        DocumentStorage::disk()->put($path, $text);
        $host = (string) preg_replace('/^www\./', '', strtolower((string) Uri::of($crawl->url)->host()));

        $document->forceFill([
            'title' => Str::limit(__('socialhq_knowledge.website.document_title', ['site' => $host]), 120, ''),
            'file_name' => "{$host}.txt",
            'mime' => DocumentType::Text->mime(),
            'size' => strlen($text),
            'path' => $path,
            'status' => DocumentStatus::Processing,
            'failure' => null,
            'source_url' => $crawl->url,
            'source_hash' => hash('sha256', $text),
            'read_at' => now(),
            'updated_by' => $user->id,
        ])->save();

        if (filled($previous)) {
            DB::afterCommit(fn () => DocumentStorage::disk()->delete((string) $previous));
        }

        ExtractKnowledgeDocument::dispatch($document->id)->afterCommit();
    }
}
