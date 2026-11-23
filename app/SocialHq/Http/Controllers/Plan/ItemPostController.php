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
use Illuminate\Validation\ValidationException;

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
     * day. Posts that couldn't move are named in the error; the others moved.
     */
    public function follow(Request $request, int $item): RedirectResponse
    {
        $kept = ItemPosts::followItem(PlanAccess::item($request, $item), $request->user())['kept'];

        if ($kept > 0) {
            throw ValidationException::withMessages(['posts' => trans_choice('socialhq_plan.posts_follow.kept', $kept, ['count' => $kept])]);
        }

        return back();
    }

    public function requireCreative(Request $request, int $item): RedirectResponse
    {
        CreatePostFromItem::waiveCreative(PlanAccess::item($request, $item, approve: true), $request->user(), waive: false);

        return back();
    }
}
