<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Usage;

use App\Models\Account;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Ai\Company\CompanyRouting;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Responses\Data\FinishReason;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Writes Settings › AI › Usage (AiUsageLog), one row per request the SDK
 * sends to the LiteLLM gateway, plus calls the app refused before sending.
 *
 * 1. The SDK announces a call (PromptingAgent, GeneratingImage, ...): we note
 *    who it runs for (company, brand, person), the feature and the task.
 * 2. Only requests to the gateway's inference endpoints are tagged with our
 *    call id (x-litellm-call-id) and tags (feature, company), so LiteLLM's
 *    own spend log can be matched. No other host ever gets these headers.
 * 3. The response writes the row: status, duration, and what LiteLLM's
 *    headers say (cost, deployment, the group that answered, fallbacks).
 * 4. The SDK's "done" event adds the token counts.
 *
 * Metadata only: never the prompt, the answer or a provider's error text.
 * Logging must never break AI, so every step swallows (and reports) its own
 * errors. Bound per request or job (scoped), so nothing carries over.
 */
final class AiUsageRecorder
{
    public const string CALL_HEADER = 'x-litellm-call-id';

    public const string TAGS_HEADER = 'x-litellm-tags';

    /** Hidden context: the person a job's AI calls run for (set on the web, carried into jobs). */
    public const string USER_CONTEXT = 'socialhq.ai_usage.user';

    /** The gateway endpoints that call a model (admin and health routes are never logged). */
    private const array INFERENCE_PATHS = [
        '/chat/completions', '/completions', '/responses', '/embeddings',
        '/images/generations', '/images/edits', '/audio/speech', '/audio/transcriptions', '/rerank',
    ];

    /**
     * Calls the SDK announced, by invocation id.
     *
     * @var array<string, array{feature: string, task: string|null, model: string|null, account_id: string|null, workspace_id: string|null, user_id: string|null, route: string|null, row_id: int|null}>
     */
    private array $invocations = [];

    /**
     * Invocations in progress, innermost last (an agent may call another).
     *
     * @var list<string>
     */
    private array $active = [];

    /**
     * Requests in flight: our call id => its invocation and start time (hrtime, ns).
     *
     * @var array<string, array{invocation: string, started: int}>
     */
    private array $calls = [];

    /** The gateway's host and port ("litellm:4000"), "" when it has no URL. */
    private ?string $gatewayAddress = null;

    /**
     * The SDK is about to call a model (PromptingAgent, StreamingAgent,
     * GeneratingEmbeddings, GeneratingImage).
     */
    public function starting(object $event): void
    {
        rescue(function () use ($event): void {
            [$invocationId, $feature, $model, $agent] = match (true) {
                $event instanceof PromptingAgent => [$event->invocationId, self::featureOf($event->prompt->agent), $event->prompt->model, $event->prompt->agent],
                $event instanceof GeneratingEmbeddings => [$event->invocationId, 'embeddings', $event->model, null],
                $event instanceof GeneratingImage => [$event->invocationId, 'image_generation', $event->model, null],
                default => [null, null, null, null],
            };

            if (blank($invocationId)) {
                return;
            }

            $this->invocations[$invocationId] = [
                ...$this->context(),
                'feature' => $feature,
                'task' => self::taskOf($agent, $model),
                'model' => $model,
                'row_id' => null,
            ];
            $this->active[] = $invocationId;
        });
    }

    /**
     * Global HTTP request middleware: tags a gateway inference request of
     * the call in progress. Anything else passes through untouched.
     */
    public function tag(RequestInterface $request): RequestInterface
    {
        if ($this->active === [] || ! $this->isGatewayInference($request->getUri())) {
            return $request;
        }

        return rescue(function () use ($request): RequestInterface {
            $invocationId = $this->active[array_key_last($this->active)];
            $call = $this->invocations[$invocationId];
            $callId = (string) Str::uuid();
            $this->calls[$callId] = ['invocation' => $invocationId, 'started' => hrtime(true)];

            $tags = array_filter(["shq-feature:{$call['feature']}", filled($call['account_id']) ? "shq-account:{$call['account_id']}" : null]);

            return $request
                ->withHeader(self::CALL_HEADER, $callId)
                ->withHeader(self::TAGS_HEADER, implode(',', $tags));
        }, $request);
    }

    public function responded(ResponseReceived $event): void
    {
        rescue(fn () => $this->record($event->request, $event->response));
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        rescue(fn () => $this->record($event->request, null));
    }

    /**
     * The SDK finished a call (AgentPrompted, AgentStreamed,
     * EmbeddingsGenerated, ImageGenerated): add its token counts.
     */
    public function finished(object $event): void
    {
        rescue(function () use ($event): void {
            $invocationId = $event->invocationId ?? null;

            if (blank($invocationId) || ! isset($this->invocations[$invocationId])) {
                return;
            }

            [$prompt, $completion] = match (true) {
                $event instanceof AgentPrompted => [$event->response->usage->promptTokens, $event->response->usage->completionTokens],
                $event instanceof ImageGenerated => [$event->response->usage->promptTokens, $event->response->usage->completionTokens],
                $event instanceof EmbeddingsGenerated => [$event->response->tokens, null],
                default => [null, null],
            };

            $rowId = $this->invocations[$invocationId]['row_id'];

            if ($rowId !== null && ($prompt !== null || $completion !== null)) {
                AiUsageLog::query()->whereKey($rowId)->update(['prompt_tokens' => $prompt, 'completion_tokens' => $completion]);
            }

            // LiteLLM answered 200, but the answer stopped at its length limit: not "answered".
            if ($rowId !== null && $event instanceof AgentPrompted && $event->response->steps->last()?->finishReason === FinishReason::Length) {
                AiUsageLog::query()->whereKey($rowId)->where('status', AiUsageStatus::Ok)->update(['status' => AiUsageStatus::CutOff, 'error_type' => 'length']);
            }

            $this->forget($invocationId);
        });
    }

    /**
     * An agent had no route (UsesAiTask): the app refused the call before
     * anything was sent.
     */
    public function blocked(object $agent, AiUnavailableException $exception): void
    {
        rescue(function () use ($agent): void {
            AiUsageLog::query()->create([
                ...$this->context(),
                'feature' => self::featureOf($agent),
                'task' => self::taskOf($agent, null),
                'status' => AiUsageStatus::BlockedNoRoute,
            ]);
        });
    }

    /**
     * The first response of a call creates its row; a later one (a tool
     * loop's next step) adds to it.
     */
    private function record(Request $request, ?Response $response): void
    {
        $callId = $request->header(self::CALL_HEADER)[0] ?? null;

        if (blank($callId) || ! isset($this->calls[$callId])) {
            return;
        }

        ['invocation' => $invocationId, 'started' => $started] = $this->calls[$callId];
        unset($this->calls[$callId]);
        $call = $this->invocations[$invocationId] ?? null;

        if ($call === null) {
            return;
        }

        $answered = $response?->header('x-litellm-model-name');
        $provider = filled($answered) && str_contains($answered, '/') ? Str::before($answered, '/') : null;
        $cost = $response?->header('x-litellm-response-cost');
        $status = self::statusOf($response);

        $values = [
            'status' => $status,
            'litellm_call_id' => $callId,
            'model_group' => self::header($response, 'x-litellm-model-group'),
            'provider' => $provider === null ? null : Str::limit($provider, 64, ''),
            'model' => filled($answered) ? Str::limit($provider === null ? $answered : Str::after($answered, '/'), 255, '') : null,
            'deployment' => self::header($response, 'x-litellm-model-id'),
            'fallbacks' => min(255, max(0, (int) $response?->header('x-litellm-attempted-fallbacks'))),
            'cost' => is_numeric($cost) ? max(0.0, (float) $cost) : null,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error_code' => $status === AiUsageStatus::Ok ? null : $response?->status(),
            'error_type' => $status === AiUsageStatus::Ok ? null : self::errorType($response),
        ];

        if ($call['row_id'] === null) {
            $row = AiUsageLog::query()->create([
                ...array_intersect_key($call, array_flip(['account_id', 'workspace_id', 'user_id', 'route', 'feature', 'task'])),
                'model_group_requested' => $call['model'] === null ? null : Str::limit($call['model'], 255, ''),
                'requests' => 1,
                ...$values,
            ]);
            $this->invocations[$invocationId]['row_id'] = $row->id;

            return;
        }

        $row = AiUsageLog::query()->find($call['row_id']);

        if ($row === null) {
            return;
        }

        $row->fill([...$values, 'cost' => ($row->cost ?? 0) + ($values['cost'] ?? 0), 'requests' => $row->requests + 1])->save();
    }

    /**
     * Who the call runs for, decided like AiRouter decides it.
     *
     * @return array{account_id: string|null, workspace_id: string|null, user_id: string|null, route: string|null}
     */
    private function context(): array
    {
        $tenant = AiTenant::current();
        $userId = auth()->id() ?? Context::getHidden(self::USER_CONTEXT);

        return [
            'account_id' => $tenant instanceof Account ? $tenant->id : null,
            'workspace_id' => AiTenant::currentWorkspaceId(),
            'user_id' => filled($userId) ? (string) $userId : null,
            'route' => CompanyRouting::current()->kind,
        ];
    }

    private function isGatewayInference(UriInterface $uri): bool
    {
        if ($this->gatewayAddress === null) {
            $gateway = Uri::of((string) config('socialhq.ai.litellm.url'));
            $this->gatewayAddress = blank($gateway->host()) ? '' : self::address((string) $gateway->scheme(), (string) $gateway->host(), $gateway->port());
        }

        return $this->gatewayAddress !== ''
            && self::address($uri->getScheme(), $uri->getHost(), $uri->getPort()) === $this->gatewayAddress
            && Str::endsWith(rtrim($uri->getPath(), '/'), self::INFERENCE_PATHS);
    }

    /**
     * "host:port" with the scheme's default port filled in, so
     * "http://litellm" and "http://litellm:80" compare equal.
     */
    private static function address(string $scheme, string $host, ?int $port): string
    {
        $port ??= strtolower($scheme) === 'https' ? 443 : 80;

        return strtolower($host).":{$port}";
    }

    private static function featureOf(object $agent): string
    {
        return Str::limit(Str::snake(class_basename($agent)), 64, '');
    }

    private static function taskOf(?object $agent, ?string $model): ?string
    {
        if ($agent !== null && method_exists($agent, 'aiTask')) {
            $task = $agent->aiTask();

            return $task instanceof AiTask ? $task->value : null;
        }

        // A LiteLLM group: shq-<task>, shq-<task>-paid or shq-<task>@<team>.
        $name = Str::before(Str::after((string) $model, 'shq-'), '@');

        return AiTask::tryFrom(Str::before($name, '-'))?->value;
    }

    private static function statusOf(?Response $response): AiUsageStatus
    {
        return match (true) {
            $response === null => AiUsageStatus::Failed,
            $response->successful() => AiUsageStatus::Ok,
            self::errorType($response) === 'budget_exceeded' => AiUsageStatus::BlockedBudget,
            $response->status() === 429 => AiUsageStatus::RateLimited,
            default => AiUsageStatus::Failed,
        };
    }

    /**
     * LiteLLM's error.type ("budget_exceeded", "rate_limit_error", ...), a
     * short identifier. Never the message: a provider's error may quote the
     * prompt or the answer. A streamed body is never read.
     */
    private static function errorType(?Response $response): ?string
    {
        if ($response === null) {
            return 'connection_failed';
        }

        if ($response->successful() || ! $response->toPsrResponse()->getBody()->isSeekable()) {
            return null;
        }

        $type = $response->json('error.type');

        return is_string($type) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $type) === 1 ? $type : null;
    }

    private static function header(?Response $response, string $name): ?string
    {
        $value = $response?->header($name);

        return filled($value) ? Str::limit($value, 255, '') : null;
    }

    private function forget(string $invocationId): void
    {
        unset($this->invocations[$invocationId]);
        $this->active = array_values(array_filter($this->active, fn (string $id): bool => $id !== $invocationId));
    }
}
