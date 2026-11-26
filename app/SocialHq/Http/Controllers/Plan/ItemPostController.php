<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\Http\Resources\App\MediaResource;
use App\Models\Media;
use App\SocialHq\Plan\CreatePostFromItem;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Create post": prepares the composer draft of an approved item (copy,
 * final media its format takes, date, posting time and channel, and the names
 * of the files left out). The page opens TryPost's composer with it.
 * Approvers can let an item's post go out text-only, without its creative.
 * An item moved to another day can take its posts along.
 */
class ItemPostController extends Controller
{
    public function store(Request $request, int $item): JsonResponse
    {
        $draft = CreatePostFromItem::execute(PlanAccess::item($request, $item), $request->user());

        return response()->json([
            'content' => $draft['content'],
            'media' => $draft['media']->map(fn (Media $media): array => MediaResource::make($media)->resolve($request))->values()->all(),
            'import_ids' => $draft['import_ids'],
            'left_out' => $draft['left_out'],
            'scheduled_on' => $draft['scheduled_on'],
            'scheduled_at' => $draft['scheduled_at'],
            'social_account_id' => $draft['social_account_id'],
        ]);
    }

    public function waiveCreative(Request $request, int $item): RedirectResponse
    {
        CreatePostFromItem::waiveCreative(PlanAccess::item($request, $item, approve: true), $request->user());

        return back();
    }

    /**
     * Moves the item's posts that are off its day to it, at the same time of
     * day, and says what happened to each: moved, moved but waiting for
     * approval (it won't go out until someone approves it), or kept at its
     * old time. One toast per outcome.
     */
    public function follow(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $result = ItemPosts::followItem($found, $request->user());
        $day = $found->scheduled_on->copy()->locale(app()->getLocale())->isoFormat('ddd D MMM');

        if ($result['moved'] > 0) {
            session()->flash('flash.success', trans_choice('socialhq_plan.posts_follow.moved', $result['moved'], ['count' => $result['moved']]));
        }

        if ($result['pending'] > 0) {
            session()->flash('flash.warning', trans_choice('socialhq_plan.posts_follow.pending', $result['pending'], ['count' => $result['pending'], 'day' => $day]));
        }

        if ($result['kept'] > 0) {
            session()->flash('flash.error', trans_choice('socialhq_plan.posts_follow.kept', $result['kept'], ['count' => $result['kept']]));
        }

        return back();
    }

    public function requireCreative(Request $request, int $item): RedirectResponse
    {
        CreatePostFromItem::waiveCreative(PlanAccess::item($request, $item, approve: true), $request->user(), waive: false);

        return back();
    }
}
