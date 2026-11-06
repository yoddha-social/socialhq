<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\SearchBrandKnowledgeRequest;
use App\SocialHq\Knowledge\BrandKnowledge;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Check what the AI reads": what BrandKnowledge::assemble() gives an AI
 * request about a topic, in order, with the space each part takes. It is
 * how the team tests search; nothing is sent to an AI.
 */
class BrandKnowledgeSearchController extends Controller
{
    public function __invoke(SearchBrandKnowledgeRequest $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless(BrandKnowledge::canView($user, $workspace), Response::HTTP_FORBIDDEN);

        $prompt = BrandKnowledge::assemble($workspace, (string) $request->validated('focus'));

        return response()->json([
            'used' => $prompt->used,
            'budget' => $prompt->budget,
            'profile' => ['used' => $prompt->profileUsed, 'trimmed' => $prompt->profileTrimmed],
            'parts' => $prompt->parts,
        ]);
    }
}
