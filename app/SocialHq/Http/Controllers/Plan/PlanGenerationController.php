<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Http\Requests\Plan\GeneratePlanRequest;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\Ai\GeneratePlanSuggestions;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlannerOptions;
use App\SocialHq\Plan\PlanOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts an AI draft of a month (the queued GeneratePlanSuggestions job);
 * the page polls until it ends. One run per plan at a time.
 */
class PlanGenerationController extends Controller
{
    /** AI drafts one person may start per hour (the socialhq-plan-generate limiter). */
    public const HOURLY_LIMIT = 5;

    public function store(GeneratePlanRequest $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        $user = $request->user();

        if (! AiRouter::isAvailable(AiTask::Structured)) {
            throw ValidationException::withMessages(['generate' => __('socialhq_plan.generation.errors.ai_unavailable')]);
        }

        $gate = Gate::inspect('useAi', $workspace->account);

        if ($gate->denied()) {
            throw ValidationException::withMessages(['generate' => (string) $gate->message()]);
        }

        $start = PlanMonth::parse($month, $user);
        $plan = PlanMonth::findOrCreate($workspace, $start, $user);
        $options = self::options($request, $start, PlanOptions::profile($workspace));

        $runId = (string) Str::uuid7();

        $started = DB::transaction(function () use ($plan, $request, $user, $runId, $options): bool {
            $locked = ContentPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($locked->isGenerating()) {
                return false;
            }

            $locked->forceFill([
                ...($request->has('goals') ? ['goals' => $request->validated('goals')] : []),
                'generation_status' => GenerationStatus::Running,
                'generation_error' => null,
                'generation_started_at' => now(),
                'generation_requested_by' => $user->id,
                'generation_run_id' => $runId,
                'generation_options' => $options->toArray(),
            ])->save();

            return true;
        });

        abort_unless($started, Response::HTTP_CONFLICT, __('socialhq_plan.generation.errors.already_running'));

        // The switches are the brand's next defaults (the days and the focus belong to this month).
        BrandProfile::query()->updateOrCreate(['workspace_id' => $workspace->id], ['planner_options' => $options->switches()]);

        GeneratePlanSuggestions::dispatch($plan->id, $runId);

        return back();
    }

    /**
     * The submitted options over the brand's remembered ones.
     */
    private static function options(GeneratePlanRequest $request, CarbonImmutable $month, ?BrandProfile $profile): PlannerOptions
    {
        $defaults = PlannerOptions::defaults($month, $profile);

        return PlannerOptions::within($month, [
            'from' => $request->validated('from', $defaults->from->toDateString()),
            'to' => $request->validated('to', $defaults->to->toDateString()),
            'old_calendars' => $request->boolean('old_calendars', $defaults->oldCalendars),
            'past_posts' => $request->boolean('past_posts', $defaults->pastPosts),
            'audience_events' => $request->boolean('audience_events', $defaults->audienceEvents),
        ]);
    }
}
