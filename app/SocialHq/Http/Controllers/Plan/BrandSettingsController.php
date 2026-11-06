<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\UpdateBrandSettingsRequest;
use App\SocialHq\Knowledge\BrandProfile;
use Illuminate\Http\RedirectResponse;

/**
 * The brand's content pillars and posts per week per channel, saved on its
 * brand profile (created when missing). Workspace admins only.
 */
class BrandSettingsController extends Controller
{
    public function update(UpdateBrandSettingsRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        BrandProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'pillars' => array_values($request->validated('pillars')),
                'cadence' => collect($request->validated('cadence'))->map(fn (mixed $count): int => (int) $count)->all(),
                'updated_by' => $request->user()->id,
            ],
        );

        return back();
    }
}
