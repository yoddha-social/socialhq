<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Usage;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Psr\Http\Message\RequestInterface;

/**
 * Wires Settings › AI › Usage (AiUsageRecorder) into the SDK's events and
 * the HTTP client. Called once from SocialHqServiceProvider::boot(), after
 * ApprovedProvidersGuard, so a call the guard refuses never gets a row.
 */
final class AiUsageLogging
{
    public static function register(Application $app): void
    {
        // One recorder per request or job: nothing carries over between Horizon jobs.
        $app->scoped(AiUsageRecorder::class);

        Event::listen([PromptingAgent::class, StreamingAgent::class, GeneratingEmbeddings::class, GeneratingImage::class],
            fn (object $event) => app(AiUsageRecorder::class)->starting($event));
        Event::listen([AgentPrompted::class, AgentStreamed::class, EmbeddingsGenerated::class, ImageGenerated::class],
            fn (object $event) => app(AiUsageRecorder::class)->finished($event));

        // Every HTTP request passes here: requests without our call id return at once.
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => app(AiUsageRecorder::class)->tag($request));
        Event::listen(ResponseReceived::class, fn (ResponseReceived $event) => $event->request->hasHeader(AiUsageRecorder::CALL_HEADER)
            ? app(AiUsageRecorder::class)->responded($event) : null);
        Event::listen(ConnectionFailed::class, fn (ConnectionFailed $event) => $event->request->hasHeader(AiUsageRecorder::CALL_HEADER)
            ? app(AiUsageRecorder::class)->connectionFailed($event) : null);

        // Jobs have no signed-in user: the web request that queued them hands
        // its user on in hidden context (never logged, carried into the job).
        Event::listen(Authenticated::class, fn (Authenticated $event) => Context::addHidden(AiUsageRecorder::USER_CONTEXT, (string) $event->user->getAuthIdentifier()));

        $prune = fn (Schedule $schedule) => $schedule->command('model:prune', ['--model' => [AiUsageLog::class]])
            ->daily()->withoutOverlapping();
        $app->afterResolving(Schedule::class, $prune);

        if ($app->resolved(Schedule::class)) {
            $prune($app->make(Schedule::class));
        }
    }
}
