<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp\Concerns;

use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanPage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * The planning tools' access and payloads: the same checks as the Plan pages
 * (PlanAccess), and no approving. A person approves plans and copy in the app.
 */
trait WorksOnPlans
{
    use AuthorizesMcpTool;

    /**
     * The token's workspace when the caller may work on its plans, else a tool error.
     */
    protected function planWorkspace(Request $request): Workspace|Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        return PlanAccess::canWork($request->user(), $workspace)
            ? $workspace
            : Response::error((new AuthorizationException)->getMessage());
    }

    /**
     * The month named by the `month` argument ("YYYY-MM"), else the caller's current month.
     */
    protected function planMonth(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['month' => ['nullable', 'string', 'date_format:Y-m']]);

        return PlanMonth::tryParse(data_get($validated, 'month')) ?? PlanMonth::current($request->user());
    }

    /**
     * The `item_id` item of this workspace; any other is not found.
     */
    protected function planItem(Request $request, Workspace $workspace): PlanItem|Response|ResponseFactory
    {
        $validated = $request->validate(['item_id' => ['required', 'integer']]);

        return PlanItem::query()->with('plan')->where('workspace_id', $workspace->id)->find((int) $validated['item_id'])
            ?? Response::error('Plan item not found.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemPayload(PlanItem $item): array
    {
        $item->loadMissing(['plan', 'owner', 'event', 'socialAccount', 'latestVersion.author']);
        $account = $item->socialAccount;

        return [
            ...PlanPage::item($item),
            'month' => PlanMonth::key($item->plan->month),
            'channel' => $account === null ? null : [
                'id' => $account->id,
                'name' => $account->display_name ?: $account->username,
                'platform' => $account->platform->value,
            ],
            'owner' => $item->owner?->name,
            'event' => $item->event === null ? null : [
                'id' => $item->event->id,
                'name' => $item->event->name,
                'starts_on' => $item->event->starts_on->toDateString(),
                'ends_on' => $item->event->ends_on->toDateString(),
                'confirmed' => $item->event->isVerified(),
            ],
            'post_created_at' => $item->post_created_at?->toIso8601ZuluString(),
            'latest_copy' => $item->latestVersion === null ? null : $this->versionPayload($item->latestVersion),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function versionPayload(ItemVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'caption' => $version->caption,
            'hashtags' => $version->hashtags,
            'script' => $version->script,
            'notes' => $version->notes,
            'source' => $version->source->value,
            'author' => $version->author?->name,
            'created_at' => $version->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reviewPayload(ItemReview $review): array
    {
        return [
            'kind' => $review->kind->value,
            'about' => $review->brief_id !== null ? 'creative' : ($review->version_id !== null ? 'copy' : null),
            'version_number' => $review->version?->number,
            'body' => $review->body,
            'by' => $review->user?->name,
            'created_at' => $review->created_at?->toIso8601ZuluString(),
        ];
    }
}
