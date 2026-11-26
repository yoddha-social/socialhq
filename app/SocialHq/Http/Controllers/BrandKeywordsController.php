<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Controllers\Concerns\WorksOnBrandKnowledge;
use App\SocialHq\Http\Requests\CheckBrandKeywordsRequest;
use App\SocialHq\Http\Requests\PostKeywordsRequest;
use App\SocialHq\Http\Requests\UpdateBrandKeywordsRequest;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordCheck;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use App\SocialHq\Knowledge\Keywords\PostContext;
use App\SocialHq\Plan\PlanAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * The brand's SEO keyword catalogue (Brand knowledge, Strategy tab). Each
 * change (add, import, tags, main or library, remove) saves the whole list
 * at once, like the notification switches: no Save button and no banner.
 * Workspace admins only.
 */
class BrandKeywordsController extends Controller
{
    use WorksOnBrandKnowledge;

    public function update(UpdateBrandKeywordsRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        BrandKeywords::save($workspace, $request->validated('keywords'));

        return back();
    }

    /**
     * "Keywords for this post" in the composer: for a post made from a plan
     * item, the item's (a person's choice there, else the app's pick for
     * it); else the app's pick for a post going out that day. Members who
     * read Brand knowledge get it.
     */
    public function forPost(PostKeywordsRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request, edit: false);
        $itemId = $request->validated('plan_item_id');

        if ($itemId !== null) {
            $item = PlanAccess::findItem($workspace, (int) $itemId);

            return response()->json(['keywords' => KeywordPicker::panel($workspace, PostContext::forItem($item), is_array($item->seo_keywords) ? $item->seo_keywords : null)]);
        }

        $day = $request->validated('date');

        return response()->json(['keywords' => KeywordPicker::panel($workspace, PostContext::forTopic(null, $day === null ? null : CarbonImmutable::parse($day)))]);
    }

    /**
     * Each verdict for keywords not saved yet (an import's preview).
     */
    public function check(CheckBrandKeywordsRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request, edit: true);
        $texts = array_values(array_unique(array_map(BrandKeywords::clean(...), $request->validated('keywords'))));

        return response()->json(['keywords' => KeywordCheck::preview($workspace, $texts)]);
    }
}
