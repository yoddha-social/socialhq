<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\UpdateNoticePreferencesRequest;
use App\SocialHq\Notices\Notices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "In the app" side of Settings › Notifications, and the live notices switch.
 */
class NoticePreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(Notices::preferences($request->user()));
    }

    public function update(UpdateNoticePreferencesRequest $request): JsonResponse
    {
        Notices::updatePreferences($request->user(), $request->validated());

        return response()->json(Notices::preferences($request->user()));
    }
}
