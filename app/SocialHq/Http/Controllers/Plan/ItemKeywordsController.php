<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\UpdateItemKeywordsRequest;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;

/**
 * "Keywords for this post" on an item page: anyone working on the plan may
 * change the keywords its copy is written with, or go back to the app's
 * pick. Nothing else about the item changes.
 */
class ItemKeywordsController extends Controller
{
    public function update(UpdateItemKeywordsRequest $request, int $item): RedirectResponse
    {
        $planItem = PlanAccess::item($request, $item);
        $chosen = $request->validated('keywords');

        $planItem->update([
            'seo_keywords' => $chosen === null ? null : array_column(KeywordPicker::chosen($planItem->workspace, $chosen), 'text'),
        ]);

        return back();
    }
}
