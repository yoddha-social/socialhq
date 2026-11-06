<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\AiGraphics\AiGraphic;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\AiGraphics\GenerateAiGraphic;
use App\SocialHq\AiGraphics\GraphicFormat;
use App\SocialHq\AiGraphics\RecentAiGraphics;
use App\SocialHq\AiGraphics\VisionCheck;
use App\SocialHq\Http\Requests\StoreAiGraphicRequest;
use App\SocialHq\Knowledge\Look\BrandLook;
use App\SocialHq\Knowledge\Look\LogoStorage;
use App\Support\MediaImportStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as ImageResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\Image;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Generate with AI" in the media menu. The request is vision-checked while
 * the person waits; the graphic is generated (and checked again) in the
 * background as a media import, which the composer attaches when done.
 */
class AiGraphicController extends Controller
{
    public function store(StoreAiGraphicRequest $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_unless($workspace !== null && AiGraphics::canUse($user, $workspace), Response::HTTP_FORBIDDEN);

        if (! AiGraphics::workspaceHasQuota($workspace)) {
            throw ValidationException::withMessages(['prompt' => __('socialhq.ai_graphics.errors.daily_limit')]);
        }

        $prompt = trim((string) $request->validated('prompt'));
        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('references', []));

        // Brand look: the logo (a bitmap) as the first reference, the colours, fonts and style in the prompt.
        $look = BrandLook::of($workspace);
        $withLogo = $request->boolean('use_brand_logo') && $look?->hasRasterLogo() && LogoStorage::disk()->exists((string) $look->logo_path);
        $brandLook = $request->boolean('use_brand_look') ? ($look?->promptText() ?: null) : null;
        $images = array_map(fn (UploadedFile $file): Image => Image::fromUpload($file), $files);

        if ($withLogo) {
            array_unshift($images, Image::fromStorage((string) $look->logo_path, LogoStorage::DISK));
        }

        $blocked = VisionCheck::review($prompt, $images, brandLogo: $withLogo);

        $route = AiRouter::route(AiTask::Image);

        $graphic = AiGraphic::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'import_id' => (string) Str::uuid(),
            'prompt' => $prompt,
            'format' => GraphicFormat::from((string) $request->validated('format')),
            'reference_count' => count($images),
            'brand_look' => $brandLook,
            'brand_logo' => $withLogo,
            'provider' => (string) array_key_first($route),
            'model' => $route[array_key_first($route)],
            'status' => $blocked === null ? AiGraphic::PENDING : AiGraphic::BLOCKED,
            'reason' => $blocked,
        ]);

        if ($blocked !== null) {
            throw ValidationException::withMessages(['prompt' => $blocked]);
        }

        $paths = array_map(fn (UploadedFile $file): string => $file->store('socialhq/ai-graphics/references', 'local'), $files);

        if ($withLogo) {
            // A copy, which the job deletes with the other references.
            $logoCopy = 'socialhq/ai-graphics/references/'.Str::uuid()->toString().'.'.pathinfo((string) $look->logo_path, PATHINFO_EXTENSION);
            Storage::disk('local')->put($logoCopy, (string) LogoStorage::disk()->get((string) $look->logo_path));
            array_unshift($paths, $logoCopy);
        }

        MediaImportStatus::pending($graphic->import_id, $user->id, $workspace->id);
        GenerateAiGraphic::dispatch($graphic->id, $paths);

        return response()->json(['import_id' => $graphic->import_id], Response::HTTP_ACCEPTED);
    }

    /**
     * The person's generated graphics of the last days in this workspace.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_unless($workspace !== null && $user->can('createPost', $workspace), Response::HTTP_FORBIDDEN);

        $look = BrandLook::of($workspace);

        return response()->json([
            // What "Use our brand look" and "Add our logo" can offer.
            'brand' => [
                'look' => filled($look?->promptText()),
                'logo' => $look?->hasRasterLogo() ? route('socialhq.brand.look.logo.show', ['v' => $look->updated_at?->timestamp]) : null,
                'vectorLogo' => $look?->hasLogo() && ! $look->hasRasterLogo(),
            ],
            'graphics' => RecentAiGraphics::for($user, $workspace)->map(fn (AiGraphic $graphic): array => [
                'import_id' => $graphic->import_id,
                'prompt' => $graphic->prompt,
                'created_at' => $graphic->created_at?->toIso8601String(),
                'preview_url' => route('socialhq.ai-graphics.image', $graphic->import_id),
            ])->values(),
        ]);
    }

    public function image(Request $request, string $import): ImageResponse
    {
        $graphic = $this->ownGenerated($request, $import);
        $bytes = RecentAiGraphics::bytes($graphic);

        abort_if($bytes === null, Response::HTTP_NOT_FOUND);

        return response($bytes, Response::HTTP_OK, [
            'Content-Type' => RecentAiGraphics::mime($bytes),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Attach a recent graphic again: a fresh upload, returned as an import.
     */
    public function attach(Request $request, string $import): JsonResponse
    {
        $graphic = $this->ownGenerated($request, $import);
        $user = $request->user();

        abort_unless($user->can('createPost', $user->currentWorkspace), Response::HTTP_FORBIDDEN);

        $importId = RecentAiGraphics::attachAgain($graphic, $user, $user->currentWorkspace);

        abort_if($importId === null, Response::HTTP_GONE);

        return response()->json(['import_id' => $importId], Response::HTTP_CREATED);
    }

    private function ownGenerated(Request $request, string $import): AiGraphic
    {
        $user = $request->user();

        return AiGraphic::query()
            ->where('import_id', $import)
            ->where('user_id', $user->id)
            ->where('workspace_id', $user->current_workspace_id)
            ->where('status', AiGraphic::GENERATED)
            ->first() ?? abort(Response::HTTP_NOT_FOUND);
    }

    public function show(Request $request, string $import): JsonResponse
    {
        $user = $request->user();

        $graphic = AiGraphic::query()
            ->where('import_id', $import)
            ->where('user_id', $user->id)
            ->where('workspace_id', $user->current_workspace_id)
            ->first();

        abort_if($graphic === null, Response::HTTP_NOT_FOUND);

        return response()->json([
            'status' => $graphic->status,
            'reason' => $graphic->reason,
        ]);
    }
}
