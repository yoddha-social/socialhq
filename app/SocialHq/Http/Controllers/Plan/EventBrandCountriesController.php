<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\BrandEventCountries;
use App\SocialHq\Http\Requests\Plan\UpdateBrandEventCountriesRequest;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;

/**
 * Which countries' dates the brand plans around: on the events page, the
 * plan and the planner. International days and the brand's own dates stay.
 */
class EventBrandCountriesController extends Controller
{
    public function update(UpdateBrandEventCountriesRequest $request): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        BrandEventCountries::save($workspace, $request->validated('countries'));

        return back()->with('flash.success', __('socialhq_events.brand_countries.saved'));
    }
}
