<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\Http\Resources\App\MediaResource;
use App\Models\Media;
use App\SocialHq\Plan\CreatePostFromItem;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Create post": prepares the composer draft of an approved item (copy,
 * final media, date, posting time and channel). The page opens TryPost's composer with it.
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
            'scheduled_on' => $draft['scheduled_on'],
            'scheduled_at' => $draft['scheduled_at'],
            'social_account_id' => $draft['social_account_id'],
        ]);
    }
}
