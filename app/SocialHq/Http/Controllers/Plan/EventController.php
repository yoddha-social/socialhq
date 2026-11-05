<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The events page: dated festivals, holidays and days, confirmed by a person.
 */
class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = PlanAccess::workspace($request);

        return Inertia::render('socialhq/plan/Events', [
            'abilities' => PlanAccess::abilities($request->user(), $workspace),
        ]);
    }
}
