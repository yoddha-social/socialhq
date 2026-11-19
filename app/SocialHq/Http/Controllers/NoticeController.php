<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\MarkNoticesReadRequest;
use App\SocialHq\Notices\Notice;
use App\SocialHq\Notices\NoticePresenter;
use App\SocialHq\Notices\Notices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification bell: a person's notices in their current workspace.
 */
class NoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $notices = Notice::query()
            ->listedFor($user, $workspace)
            ->latest('created_at')
            ->latest('id')
            ->limit(Notice::LISTED_LIMIT)
            ->get();

        return response()->json([
            'notices' => NoticePresenter::list($notices),
            'unread' => Notices::unreadCount($user, $workspace),
        ]);
    }

    public function read(MarkNoticesReadRequest $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        Notice::query()
            ->listedFor($user, $workspace)
            ->whereNull('read_at')
            ->when($request->has('ids'), fn ($query) => $query->whereIn('id', $request->validated('ids')))
            ->update(['read_at' => now()]);

        return response()->json(['unread' => Notices::unreadCount($user, $workspace)]);
    }
}
