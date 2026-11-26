<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Controllers\Concerns\WorksOnBrandKnowledge;
use App\SocialHq\Http\Requests\CheckBrandKeywordsRequest;
use App\SocialHq\Http\Requests\UpdateBrandKeywordsRequest;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * The brand's SEO keywords (Brand knowledge, Strategy tab). Each change
 * saves the whole list at once, like the notification switches: no Save
 * button and no banner. Workspace admins only.
 */
class BrandKeywordsController extends Controller
{
    use WorksOnBrandKnowledge;

    public function update(UpdateBrandKeywordsRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        BrandProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['keywords' => BrandKeywords::normalize($request->validated('keywords')), 'updated_by' => $request->user()->id],
        );

        return back();
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
