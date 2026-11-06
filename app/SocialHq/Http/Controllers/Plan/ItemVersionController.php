<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\StoreItemVersionRequest;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;

/**
 * The versions of an item's copy. A save always adds a version.
 */
class ItemVersionController extends Controller
{
    public function store(StoreItemVersionRequest $request, int $item): RedirectResponse
    {
        $planItem = PlanAccess::item($request, $item);

        ItemCopy::saveVersion($planItem, $request->user(), $request->validated());

        return back();
    }
}
