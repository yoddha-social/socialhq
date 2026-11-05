<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One plan item: its copy, review and brief.
 */
class PlanItemController extends Controller
{
    public function show(Request $request, int $item): Response
    {
        $workspace = PlanAccess::workspace($request);

        return Inertia::render('socialhq/plan/Item', [
            'abilities' => PlanAccess::abilities($request->user(), $workspace),
        ]);
    }
}
