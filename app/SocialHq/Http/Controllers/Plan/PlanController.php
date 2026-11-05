<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\UpdatePlanGoalsRequest;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanPage;
use App\SocialHq\Plan\PlanStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A brand's content plan for one month: the page, the team's goals, sending
 * it for review and approving it. The plan row is created on the first
 * write, never on view.
 */
class PlanController extends Controller
{
    public function show(Request $request, ?string $month = null): Response
    {
        $workspace = PlanAccess::workspace($request);

        return Inertia::render('socialhq/plan/Month', PlanPage::props($request->user(), $workspace, PlanMonth::parse($month, $request->user())));
    }

    public function updateGoals(UpdatePlanGoalsRequest $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);

        PlanMonth::findOrCreate($workspace, PlanMonth::parse($month, $request->user()), $request->user())
            ->update(['goals' => $request->validated('goals')]);

        return back();
    }

    /**
     * Draft → in review, by anyone who works on the plan.
     */
    public function submit(Request $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        $plan = PlanMonth::find($workspace, PlanMonth::parse($month, $request->user()));

        $this->ensureHasPlannedItems($plan);

        if ($plan->status === PlanStatus::Draft) {
            $plan->forceFill(['status' => PlanStatus::InReview, 'submitted_at' => now(), 'submitted_by' => $request->user()->id])->save();
        }

        return back();
    }

    /**
     * Approvers only, and only once every AI suggestion was accepted or dropped.
     */
    public function approve(Request $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $plan = PlanMonth::find($workspace, PlanMonth::parse($month, $request->user()));

        $this->ensureHasPlannedItems($plan);

        if ($plan->hasOpenSuggestions()) {
            throw ValidationException::withMessages(['plan' => __('socialhq_plan.errors.open_suggestions')]);
        }

        if ($plan->status !== PlanStatus::Approved) {
            $plan->forceFill([
                'status' => PlanStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'submitted_at' => $plan->submitted_at ?? now(),
                'submitted_by' => $plan->submitted_by ?? $request->user()->id,
            ])->save();
        }

        return back();
    }

    /**
     * @phpstan-assert ContentPlan $plan
     */
    private function ensureHasPlannedItems(?ContentPlan $plan): void
    {
        if ($plan === null || ! $plan->items()->where('status', ItemStatus::Planned)->exists()) {
            throw ValidationException::withMessages(['plan' => __('socialhq_plan.errors.empty_plan')]);
        }
    }
}
