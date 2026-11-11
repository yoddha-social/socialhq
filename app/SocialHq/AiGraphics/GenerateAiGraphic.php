<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\Support\MediaImportStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Image as ImageGeneration;
use Throwable;

/**
 * Generates the graphic, puts it through the vision check, and stores it as
 * a temporary upload the composer attaches like any imported file.
 */
class GenerateAiGraphic implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param  list<string>  $referencePaths  on the local disk; deleted when done
     */
    public function __construct(public int $aiGraphicId, public array $referencePaths = [])
    {
        $this->onQueue(ImportRemoteMedia::QUEUE);
        $this->timeout = AiGraphics::timeoutSeconds() + 60;
    }

    public function handle(): void
    {
        $graphic = AiGraphic::query()->find($this->aiGraphicId);

        try {
            if ($graphic === null || $graphic->status !== AiGraphic::PENDING) {
                return;
            }

            $workspace = Workspace::query()->find($graphic->workspace_id);
            $user = User::query()->find($graphic->user_id);

            if ($workspace === null || $user === null || ! $user->can('createPost', $workspace)) {
                $this->finish($graphic, AiGraphic::FAILED, __('socialhq.ai_graphics.errors.no_access'));

                return;
            }

            // The job has no signed-in user: the workspace decides whose AI it uses.
            AiTenant::run($workspace, fn () => $this->generate($graphic, $workspace));
        } catch (Throwable $exception) {
            report($exception);

            if ($graphic !== null) {
                $this->finish($graphic, AiGraphic::FAILED, __('socialhq.ai_graphics.errors.generation_failed'));
            }
        } finally {
            Storage::disk('local')->delete($this->referencePaths);
        }
    }

    private function generate(AiGraphic $graphic, Workspace $workspace): void
    {
        $references = array_map(fn (string $path): Image => Image::fromStorage($path, 'local'), $this->referencePaths);

        $response = ImageGeneration::of(self::fullPrompt($graphic))
            ->attachments($references)
            ->size($graphic->format->aspectRatio(AiRouter::sharedDriver(AiTask::Image)))
            ->timeout(AiGraphics::timeoutSeconds())
            ->generate(AiRouter::route(AiTask::Image));
        $image = $response->firstImage();

        // The route may have failed over: record what actually made it.
        $graphic->update([
            'provider' => $response->meta->provider ?? $graphic->provider,
            'model' => $response->meta->model ?? $graphic->model,
        ]);

        $blocked = VisionCheck::review($graphic->prompt, [Image::fromBase64($image->image, $image->mime())], reviewsResult: true);

        if ($blocked !== null) {
            $this->finish($graphic, AiGraphic::BLOCKED, $blocked);

            return;
        }

        $this->store($graphic, $workspace, $image->content(), $image->mime());
    }

    /**
     * The user's prompt with what makes it a social media graphic.
     */
    public static function fullPrompt(AiGraphic $graphic): string
    {
        $references = $graphic->reference_count > 0
            ? ' Use the attached reference image'.($graphic->reference_count > 1 ? 's' : '').' as the prompt describes.'
            : '';

        return trim($graphic->prompt)."\n\nA finished still graphic for a social media post, ".$graphic->format->value.' format.'.$references
            .' Any text in the image must be spelled exactly as written in the prompt.';
    }

    private function store(AiGraphic $graphic, Workspace $workspace, string $bytes, string $mime): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shq-ai-graphic-');
        $extension = AiGraphics::extensionFor($mime);

        try {
            file_put_contents($path, $bytes);

            $media = $workspace->addMediaFromPath(
                $path,
                "ai-graphic-{$graphic->id}.{$extension}",
                Media::COLLECTION_UPLOADS,
                ['source' => 'socialhq_ai', 'ai_graphic_id' => $graphic->id],
                mimeType: $mime,
            );
            $media->issueUploadToken();
        } finally {
            @unlink($path);
        }

        $resultPath = "socialhq/ai-graphics/results/{$graphic->id}.{$extension}";
        Storage::disk('local')->put($resultPath, $bytes);

        $graphic->update(['status' => AiGraphic::GENERATED, 'media_id' => $media->id, 'result_path' => $resultPath]);
        MediaImportStatus::complete($graphic->import_id, $media);
    }

    private function finish(AiGraphic $graphic, string $status, string $reason): void
    {
        $graphic->update(['status' => $status, 'reason' => $reason]);
        MediaImportStatus::fail($graphic->import_id, $status === AiGraphic::BLOCKED ? 'ai_blocked' : 'ai_failed');
    }

    public function failed(?Throwable $exception): void
    {
        $graphic = AiGraphic::query()->find($this->aiGraphicId);

        if ($graphic !== null && $graphic->status === AiGraphic::PENDING) {
            $this->finish($graphic, AiGraphic::FAILED, __('socialhq.ai_graphics.errors.generation_failed'));
        }

        Storage::disk('local')->delete($this->referencePaths);
    }
}
