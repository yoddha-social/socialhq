<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\UpdateAudienceRequest;
use App\SocialHq\Knowledge\BrandProfile;
use Illuminate\Http\RedirectResponse;

/**
 * Where the brand's audience is, saved on its brand profile (created when
 * missing), largest share first. Workspace admins only; shown on Brand
 * knowledge and read by the planner.
 */
class AudienceController extends Controller
{
    public function update(UpdateAudienceRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        BrandProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'audience_locations' => collect($request->validated('locations'))
                    ->map(fn (array $row): array => [
                        'country' => data_get($row, 'country'),
                        'city' => data_get($row, 'city'),
                        'timezone' => data_get($row, 'timezone'),
                        'share' => (int) data_get($row, 'share'),
                    ])
                    ->sortByDesc('share')
                    ->values()
                    ->all(),
                'updated_by' => $request->user()->id,
            ],
        );

        return back();
    }
}
