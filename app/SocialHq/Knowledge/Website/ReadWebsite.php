<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\Permission;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\KnowledgeEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Read our website": crawls the site (WebsiteCrawler), keeps only text
 * that is new across pages and cuts it to the AI budget, then asks
 * WebsiteReader once for suggestions, and WebsiteKeywordReader once for SEO
 * keywords from what the same pages stress (WebsiteKeywordSignals, no
 * more crawling). Only the cleaned, deduplicated, cut text goes to the AI,
 * and not at all when it is the same as the last run's (the last
 * suggestions carry over; a run from before keyword suggestions gets them
 * once). A keyword step that fails leaves the other suggestions.
 */
class ReadWebsite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** The crawl (up to 150 s) plus the queued AI call (AiTimeout::QUEUED, 300 s). */
    public int $timeout = 480;

    public bool $failOnTimeout = true;

    public function __construct(public int $crawlId)
    {
        $this->onQueue('default');
    }

    public function handle(WebsiteCrawler $crawler): void
    {
        $crawl = WebsiteCrawl::query()->with('workspace')->find($this->crawlId);

        if ($crawl === null || $crawl->status !== CrawlStatus::Queued || $crawl->workspace === null) {
            return;
        }

        $workspace = $crawl->workspace;
        $user = User::query()->find($crawl->requested_by);

        if ($user === null || ! BrandKnowledge::canEdit($user, $workspace) || ! $user->hasPermissionIn($workspace, Permission::AiUse)) {
            $this->markFailed($crawl, CrawlFailure::NoAccess);

            return;
        }

        $crawl->update(['status' => CrawlStatus::Reading]);

        try {
            $result = $crawler->crawl($workspace, $crawl->url);
        } catch (CrawlStopped $stopped) {
            $this->markFailed($crawl, $stopped->failure);

            return;
        }

        $pages = WebsiteText::deduplicate($result->pages);
        $crawl->update(['pages_read' => $result->read, 'pages_unchanged' => $result->unchanged, 'pages_skipped' => $result->skipped]);

        if ($pages === []) {
            $this->markFailed($crawl, CrawlFailure::NoText);

            return;
        }

        $forAi = WebsiteText::cap(
            $pages,
            max(500, (int) config('socialhq.knowledge.website.page_characters', 4000)),
            max(2000, (int) config('socialhq.knowledge.website.ai_characters', 20000)),
        );
        $site = ['url' => $crawl->url, 'pages' => $forAi, 'colours_found' => $result->colours, 'fonts_found' => $result->fonts];
        $inputHash = hash('sha256', (string) json_encode($site, JSON_UNESCAPED_UNICODE));
        $crawl->update(['characters' => array_sum(array_map(fn (array $page): int => mb_strlen($page['text']), $forAi)), 'input_hash' => $inputHash]);

        $previous = WebsiteCrawl::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', '<', $crawl->id)
            ->whereIn('status', [CrawlStatus::Ready->value, CrawlStatus::Unchanged->value])
            ->latest('id')
            ->first();

        if ($previous !== null && $previous->input_hash === $inputHash) {
            $carried = array_values((array) $previous->suggestions);
            $hasKeywords = collect($carried)->contains(fn (mixed $suggestion): bool => data_get($suggestion, 'type') === 'keyword');

            $crawl->update([
                'status' => CrawlStatus::Unchanged,
                'suggestions' => $hasKeywords ? $carried : [...$carried, ...self::keywordSuggestions($workspace, $user, $pages, $crawl->url)],
                'finished_at' => now(),
            ]);

            return;
        }

        $crawl->update(['status' => CrawlStatus::Drafting]);

        try {
            // The job has no signed-in user: the workspace decides whose AI it uses.
            $response = AiTenant::run($workspace, fn () => (new WebsiteReader(($user->locale ?? Locale::DEFAULT)->promptLanguage()))
                ->prompt((string) json_encode([...$site, 'team_has' => self::teamHas($workspace)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        } catch (AiUnavailableException $exception) {
            // No AI set up for the company is a configuration state, not an error to report.
            if (! $exception->isConfigurationState()) {
                report($exception);
            }

            $this->markFailed($crawl, CrawlFailure::AiUnavailable);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($crawl, CrawlFailure::AiFailed);

            return;
        }

        $crawl->update([
            'status' => CrawlStatus::Ready,
            'suggestions' => [
                ...WebsiteSuggestions::build($response, $workspace, $result->colours, count($pages)),
                ...self::keywordSuggestions($workspace, $user, $pages, $crawl->url),
            ],
            'provider' => $response->meta->provider ?? null,
            'model' => isset($response->meta->model) ? Str::limit((string) $response->meta->model, 160, '') : null,
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $crawl = WebsiteCrawl::query()->find($this->crawlId);

        if ($crawl !== null && $crawl->status->isRunning()) {
            $this->markFailed($crawl, $crawl->status === CrawlStatus::Drafting ? CrawlFailure::AiFailed : CrawlFailure::Unreachable);
        }
    }

    /**
     * SEO keyword suggestions from what the pages stress: one more AI call
     * on what was already read. Its failure is reported (unless no AI is set
     * up) and leaves no keyword suggestions, never a failed run.
     *
     * @param  list<array{url: string, title: string, text: string}>  $pages
     * @return list<array<string, mixed>>
     */
    private static function keywordSuggestions(Workspace $workspace, User $user, array $pages, string $url): array
    {
        try {
            $mainSlots = WebsiteKeywordSuggestions::mainSlots($workspace);
            $brandNames = WebsiteKeywordSuggestions::brandNames($workspace, $url);
            $prompt = [
                'brand' => ['name' => (string) $workspace->name, 'site' => $url],
                ...WebsiteKeywordSignals::of($pages, $brandNames),
                'team_has' => WebsiteKeywordSuggestions::teamHas($workspace),
            ];
            $response = AiTenant::run($workspace, fn () => (new WebsiteKeywordReader(($user->locale ?? Locale::DEFAULT)->promptLanguage(), $mainSlots))
                ->prompt((string) json_encode($prompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));

            return WebsiteKeywordSuggestions::build($response, $workspace, $pages, $url);
        } catch (AiUnavailableException $exception) {
            if (! $exception->isConfigurationState()) {
                report($exception);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return [];
    }

    /**
     * What the brand already has, so the AI suggests only what is new.
     *
     * @return array{profile: array<string, string>, entry_titles: list<string>}
     */
    private static function teamHas(Workspace $workspace): array
    {
        $profile = BrandProfile::query()->where('workspace_id', $workspace->id)->first();

        return [
            'profile' => collect(['about', 'audience', 'voice'])->mapWithKeys(fn (string $field): array => [$field => Str::limit(trim((string) $profile?->{$field}), 600)])->all(),
            'entry_titles' => KnowledgeEntry::query()->where('workspace_id', $workspace->id)->latest('id')->limit(80)->pluck('title')->all(),
        ];
    }

    private function markFailed(WebsiteCrawl $crawl, CrawlFailure $failure): void
    {
        $crawl->update(['status' => CrawlStatus::Failed, 'failure' => $failure, 'finished_at' => now()]);
    }
}
