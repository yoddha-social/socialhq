<?php

declare(strict_types=1);

namespace App\SocialHq\Mcp;

use App\Models\Post;
use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Makes create-post-tool and create-posts-tool safe to retry. An agent whose
 * call timed out sends it again; with the same idempotency_key and the same
 * arguments it gets the posts the first call made instead of duplicates.
 *
 * The key is remembered for KEEP_HOURS per caller, workspace and tool, in the
 * cache. The lookup runs before validation, so a retry is not refused for a
 * queue slot its own first call took. A call that failed stores nothing and
 * runs again. Without a key nothing changes.
 */
final class IdempotentPostCreation
{
    public const KEEP_HOURS = 24;

    public const MAX_KEY_LENGTH = 255;

    private const WAIT_SECONDS = 30;

    private const LOCK_SECONDS = 120;

    /**
     * @param  Closure(): (Collection<int, Post>|Response|ResponseFactory)  $create  validates and creates; an error response is returned as is
     * @param  Closure(Collection<int, Post>): (Response|ResponseFactory)  $respond
     */
    public static function run(Request $request, Workspace $workspace, string $tool, Closure $create, Closure $respond): Response|ResponseFactory
    {
        $request->validate(['idempotency_key' => ['nullable', 'string', 'max:'.self::MAX_KEY_LENGTH]]);

        $key = $request->get('idempotency_key');

        if (blank($key)) {
            $result = $create();

            return $result instanceof Collection ? $respond($result) : $result;
        }

        $cacheKey = 'socialhq:mcp-idempotency:'.hash('sha256', implode('|', [$request->user()?->getAuthIdentifier(), $workspace->id, $tool, $key]));
        $fingerprint = self::fingerprint($request);

        try {
            return Cache::lock("{$cacheKey}:lock", self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($cacheKey, $fingerprint, $workspace, $create, $respond): Response|ResponseFactory {
                $stored = Cache::get($cacheKey);

                if (is_array($stored)) {
                    if (data_get($stored, 'fingerprint') !== $fingerprint) {
                        return Response::error(__('socialhq.agents.idempotency_key_reused'));
                    }

                    $posts = self::storedPosts($workspace, (array) data_get($stored, 'post_ids', []));

                    return $posts->isEmpty() ? Response::error(__('socialhq.agents.idempotency_key_deleted')) : $respond($posts);
                }

                $result = $create();

                if (! $result instanceof Collection) {
                    return $result;
                }

                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'post_ids' => $result->map(fn (Post $post): string => $post->id)->values()->all(),
                ], now()->addHours(self::KEEP_HOURS));

                return $respond($result);
            });
        } catch (LockTimeoutException) {
            return Response::error(__('socialhq.agents.idempotency_key_busy'));
        }
    }

    public static function schemaDescription(): string
    {
        return 'Optional. Any unique string (a UUID works) that makes a retry safe: calling again with the same idempotency_key and the same arguments within '.self::KEEP_HOURS.' hours returns the posts the first call created instead of creating them again. Reusing a key with different arguments is refused.';
    }

    /**
     * The arguments without the key, in a stable order.
     */
    private static function fingerprint(Request $request): string
    {
        return hash('sha256', (string) json_encode(Arr::sortRecursive(Arr::except($request->all(), 'idempotency_key'))));
    }

    /**
     * @param  array<int, string>  $postIds
     * @return Collection<int, Post>
     */
    private static function storedPosts(Workspace $workspace, array $postIds): Collection
    {
        $posts = Post::query()->where('workspace_id', $workspace->id)->whereIn('id', $postIds)->get()->keyBy('id');

        return collect($postIds)->map(fn (string $id): ?Post => $posts->get($id))->filter()->values();
    }
}
