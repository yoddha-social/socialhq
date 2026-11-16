<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\StoreItemCommentRequest;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\ReviewKind;
use Illuminate\Http\RedirectResponse;

/**
 * Comments in an item's review thread. Commenting on an AI suggestion
 * accepts it, so a new AI draft never deletes the conversation.
 */
class ItemCommentController extends Controller
{
    public function store(StoreItemCommentRequest $request, int $item): RedirectResponse
    {
        $planItem = PlanAccess::item($request, $item);
        $about = $request->validated('about');
        $brief = $about === 'creative' ? $planItem->brief : null;

        ItemReview::query()->create([
            'item_id' => $planItem->id,
            'version_id' => $about === 'copy'
                ? ItemVersion::query()->where('item_id', $planItem->id)->orderByDesc('number')->value('id')
                : null,
            'brief_id' => $brief?->id,
            'round' => $brief !== null ? max(1, $brief->currentRound()) : null,
            'user_id' => $request->user()->id,
            'kind' => ReviewKind::Comment,
            'body' => $request->validated('body'),
        ]);

        $planItem->acceptForWork();

        return back();
    }
}
