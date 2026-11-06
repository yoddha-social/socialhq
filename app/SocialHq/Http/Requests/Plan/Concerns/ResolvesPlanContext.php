<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan\Concerns;

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * For plan FormRequests: the current workspace, the item named in the route
 * (only one of this workspace, else 404) and the month the values must fit.
 * Lookups only; each request declares its own rules.
 */
trait ResolvesPlanContext
{
    private ?PlanItem $resolvedItem = null;

    /** @var Collection<int, SocialAccount>|null */
    private ?Collection $resolvedChannels = null;

    protected function planWorkspace(): Workspace
    {
        $workspace = $this->user()?->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);

        return $workspace;
    }

    protected function planItem(): PlanItem
    {
        return $this->resolvedItem ??= PlanItem::query()
            ->with('plan')
            ->where('workspace_id', $this->planWorkspace()->id)
            ->findOrFail((int) $this->route('item'));
    }

    /**
     * The first day of the month the item belongs to (or the route's month for a new item).
     */
    protected function planMonth(): CarbonImmutable
    {
        if ($this->route('item') !== null) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $this->planItem()->plan->month->toDateString(), 'UTC');
        }

        return PlanMonth::parse((string) $this->route('month'), $this->user());
    }

    /**
     * Every channel of the workspace, connected or not: an item may stay on a channel that lost its connection.
     *
     * @return Collection<int, SocialAccount>
     */
    protected function workspaceChannels(): Collection
    {
        return $this->resolvedChannels ??= $this->planWorkspace()->socialAccounts()->get();
    }

    /**
     * @return list<int>
     */
    protected function monthEventIds(): array
    {
        $month = $this->planMonth();

        $ids = PlanOptions::events($this->planWorkspace(), $month, PlanMonth::end($month))->pluck('id')->all();

        // The item's current event stays allowed (deleted, moved or made another brand's since), so it doesn't block other edits.
        if ($this->route('item') !== null && $this->planItem()->event_id !== null) {
            $ids[] = $this->planItem()->event_id;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    protected function ownerIds(): array
    {
        $ids = PlanOptions::owners($this->planWorkspace())->pluck('id')->all();

        // An owner who has left the workspace stays allowed on the item they own, until someone changes it.
        if ($this->route('item') !== null && $this->planItem()->owner_id !== null) {
            $ids[] = $this->planItem()->owner_id;
        }

        return $ids;
    }

    /**
     * The brand's pillars, plus the item's current one so a renamed pillar doesn't block other edits.
     *
     * @return list<string>
     */
    protected function allowedPillars(): array
    {
        $pillars = PlanOptions::pillars(PlanOptions::profile($this->planWorkspace()));

        if ($this->route('item') !== null && filled($this->planItem()->pillar)) {
            $pillars[] = $this->planItem()->pillar;
        }

        return $pillars;
    }
}
