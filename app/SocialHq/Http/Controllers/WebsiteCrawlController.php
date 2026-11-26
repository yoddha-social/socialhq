<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Http\Controllers\Concerns\WorksOnBrandKnowledge;
use App\SocialHq\Http\Requests\AcceptWebsiteSuggestionRequest;
use App\SocialHq\Http\Requests\StoreWebsiteCrawlRequest;
use App\SocialHq\Knowledge\Website\CrawlStatus;
use App\SocialHq\Knowledge\Website\ReadWebsite;
use App\SocialHq\Knowledge\Website\WebsiteCrawl;
use App\SocialHq\Knowledge\Website\WebsiteReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Read our website" on Brand knowledge: a workspace admin starts a run
 * (ReadWebsite, queued), previews the "Website text" suggestion and decides
 * on each suggestion. Nothing reaches brand knowledge until it is accepted.
 */
class WebsiteCrawlController extends Controller
{
    use WorksOnBrandKnowledge;

    public function store(StoreWebsiteCrawlRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request);

        if (! AiRouter::isAvailable(AiTask::Structured)) {
            throw ValidationException::withMessages(['url' => __('socialhq_knowledge.website.errors.ai_unavailable')]);
        }

        if (WebsiteCrawl::runningIn($workspace) !== null) {
            throw ValidationException::withMessages(['url' => __('socialhq_knowledge.website.errors.running')]);
        }

        $crawl = WebsiteCrawl::query()->create([
            'workspace_id' => $workspace->id,
            'url' => $request->crawlUrl(),
            'status' => CrawlStatus::Queued,
            'requested_by' => $request->user()->id,
        ]);

        ReadWebsite::dispatch($crawl->id);

        return back();
    }

    public function accept(AcceptWebsiteSuggestionRequest $request, int $crawl, string $suggestion): RedirectResponse
    {
        WebsiteReview::accept($this->workspace($request), $crawl, $suggestion, $request->validated(), $request->user());

        return back();
    }

    /**
     * The "Website text" suggestion's pages, from the text the read stored.
     */
    public function preview(Request $request, int $crawl, string $suggestion): JsonResponse
    {
        return response()->json(WebsiteReview::preview($this->workspace($request), $crawl, $suggestion), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function skip(Request $request, int $crawl, string $suggestion): RedirectResponse
    {
        WebsiteReview::skip($this->workspace($request), $crawl, $suggestion);

        return back();
    }
}
