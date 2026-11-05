<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Http\Requests\Plan\GeneratePlanRequest;
use App\SocialHq\Plan\Ai\GeneratePlanSuggestions;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
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

        $plan = PlanMonth::findOrCreate($workspace, PlanMonth::parse($month, $user), $user);

        $runId = (string) Str::uuid7();

        $started = DB::transaction(function () use ($plan, $request, $user, $runId): bool {
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
            ])->save();

            return true;
        });

        abort_unless($started, Response::HTTP_CONFLICT, __('socialhq_plan.generation.errors.already_running'));

        GeneratePlanSuggestions::dispatch($plan->id, $runId);

        return back();
    }
}
