<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A brand's content plan for one month.
 */
class PlanController extends Controller
{
    public function show(Request $request, ?string $month = null): Response
    {
        $workspace = PlanAccess::workspace($request);

        return Inertia::render('socialhq/plan/Month', [
            'abilities' => PlanAccess::abilities($request->user(), $workspace),
        ]);
    }
}
