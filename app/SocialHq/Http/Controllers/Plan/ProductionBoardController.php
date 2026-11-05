<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\MoveBriefRequest;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefStage;
use App\SocialHq\Production\ProductionBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The production board: every brief of the brand by stage.
 */
class ProductionBoardController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = PlanAccess::workspace($request);
        $user = $request->user();

        $kind = BriefKind::tryFrom((string) $request->query('kind'))?->value;
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) === 1 ? (string) $request->query('month') : null;
        $filters = ['mine' => $request->boolean('mine'), 'kind' => $kind, 'month' => $month];

        return Inertia::render('socialhq/plan/Production', [
            'stages' => array_map(fn (BriefStage $stage): string => $stage->value, BriefStage::cases()),
            'cards' => ProductionBoard::cards($workspace, $user, $filters),
            'hasBriefs' => Brief::query()->where('workspace_id', $workspace->id)->exists(),
            'filters' => $filters,
            'months' => ProductionBoard::months($workspace),
            'approverNames' => ProductionBoard::approverNames($workspace),
            'highlight' => $request->integer('brief') ?: null,
            'abilities' => PlanAccess::abilities($user, $workspace),
        ]);
    }

    public function move(MoveBriefRequest $request, int $brief): JsonResponse
    {
        $workspace = PlanAccess::workspace($request);
        $card = Brief::query()->where('workspace_id', $workspace->id)->findOrFail($brief);

        ProductionBoard::move($card, $request->user(), BriefStage::from($request->validated('stage')), array_map('intval', $request->validated('ordered_ids')));

        return response()->json(['stage' => $card->fresh()->stage->value], HttpResponse::HTTP_OK);
    }
}
