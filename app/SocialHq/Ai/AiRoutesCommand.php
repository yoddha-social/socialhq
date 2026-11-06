<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Image as ImageGeneration;
use Laravel\Ai\Responses\Data\Meta;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Shows where each AI task goes and, with --live, sends one small request
 * through each task to prove the route answers.
 */
class AiRoutesCommand extends Command
{
    protected $signature = 'socialhq:ai-routes
        {--live : Send a small request through each text, vision and embedding task}
        {--images : With --live, also generate one image (may cost money)}
        {--account= : Route as this company (its account id) instead of the platform}';

    protected $description = 'Show the AI route of every task, optionally checking each one live';

    /** A 64 px red square, for the vision probe. */
    private const RED_SQUARE = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAUElEQVR42u3PQREAAAQAMATRP5QwUni42xospzs+q3hOQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBA4N4CCJ0BmOb2hBcAAAAASUVORK5CYII=';

    public function handle(): int
    {
        $accountId = $this->option('account');

        if (blank($accountId)) {
            return AiTenant::run(AiTenant::PLATFORM, fn (): int => $this->show());
        }

        $account = Account::query()->find($accountId);

        if ($account === null) {
            $this->error('No account has that id.');

            return self::FAILURE;
        }

        return AiTenant::run($account, fn (): int => $this->show());
    }

    private function show(): int
    {
        $this->line('Gateway: '.(AiRouter::usesGateway() ? 'litellm' : 'direct').', as '.(filled($this->option('account')) ? "account {$this->option('account')}" : 'the platform'));

        $rows = [];

        foreach (AiTask::cases() as $task) {
            foreach (AiRouter::explain($task) as $target) {
                $rows[] = [
                    $task->value,
                    $target['provider'].($target['model'] !== null ? ":{$target['model']}" : ''),
                    $target['used'] ? 'yes' : "no ({$target['reason']})",
                ];
            }

            if (AiRouter::explain($task) === []) {
                $rows[] = [$task->value, '-', 'no route'];
            }
        }

        $this->table(['Task', 'Target', 'Used'], $rows);

        if (! $this->option('live')) {
            return self::SUCCESS;
        }

        $failed = false;

        foreach (AiTask::cases() as $task) {
            if ($task === AiTask::Image && ! $this->option('images')) {
                $this->line('image: skipped (add --images)');

                continue;
            }

            if (! AiRouter::isAvailable($task)) {
                $this->warn("{$task->value}: unavailable");

                continue;
            }

            $started = microtime(true);

            try {
                [$answer, $meta] = $this->probe($task);
                $ms = (int) round((microtime(true) - $started) * 1000);
                $this->info("{$task->value}: ok in {$ms} ms via {$meta?->provider}:{$meta?->model}, answered ".Str::limit($answer, 60));
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$task->value}: failed, ".Str::limit($exception->getMessage(), 200));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: Meta|null}
     */
    private function probe(AiTask $task): array
    {
        $route = AiRouter::route($task);

        return match ($task) {
            AiTask::Write => $this->answer(agent('Reply with the single word OK.')->prompt('Ping.', provider: $route, timeout: 60)),
            AiTask::Structured => $this->answer(agent(
                'Answer with the requested JSON.',
                schema: fn (JsonSchema $schema): array => ['word' => $schema->string()->required()],
            )->prompt('Return the word "ok" in the field word.', provider: $route, timeout: 60), 'word'),
            AiTask::Vision => $this->answer(agent('Answer in one word.')->prompt(
                'What colour is this square?',
                attachments: [Image::fromBase64(self::RED_SQUARE, 'image/png')],
                provider: $route,
                timeout: 60,
            )),
            AiTask::Embed => (function () use ($route): array {
                $response = Embeddings::for(['ping'])->generate($route);

                return [count($response->embeddings[0] ?? []).' dimensions', $response->meta];
            })(),
            AiTask::Image => (function () use ($route): array {
                $response = ImageGeneration::of('A plain red circle on a white background.')->square()->timeout(180)->generate($route);

                return [strlen($response->firstImage()->content()).' bytes', $response->meta];
            })(),
        };
    }

    /**
     * @return array{0: string, 1: Meta|null}
     */
    private function answer(mixed $response, ?string $field = null): array
    {
        $text = $field === null ? trim((string) $response) : (string) ($response[$field] ?? '');

        return [$text, $response->meta ?? null];
    }
}
