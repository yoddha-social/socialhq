<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\AiGraphics\AiGraphic;
use App\SocialHq\AiGraphics\GenerateAiGraphic;
use App\SocialHq\Companies\Company;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\AiException;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'litellm',
        'socialhq.ai.approved_providers' => ['litellm', 'litellm-images'],
        'socialhq.ai.litellm.url' => 'http://litellm.test/v1',
        'socialhq.ai.litellm.key' => 'sk-platform-key',
        'socialhq.ai.litellm.tasks' => ['write', 'structured', 'vision'],
        'socialhq.ai.routes' => array_fill_keys(array_map(fn (AiTask $task): string => $task->value, AiTask::cases()), null),
        'socialhq.ai_graphics.enabled' => true,
        'trypost.self_hosted' => true,
    ]);
    AiRouter::registerGateway();
    app(AiManager::class)->forgetInstance([AiRouter::LITELLM, AiRouter::LITELLM_IMAGES]);
    Http::fake();
    Exceptions::fake();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    // An approved company with no AI of its own, not allowed the platform's.
    $this->company = Company::factory()->create(['account_id' => $this->owner->account_id]);
    $this->company->forceFill(['uses_platform_ai' => false])->save();

    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'creative' => CreativeKind::Image]);
    Brief::factory()->for($this->item, 'item')->create();
});

test('every AI endpoint says how to set AI up, with a 409, and reports nothing', function (Closure $request) {
    $request($this)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('socialhq_ai.unavailable.owner'));

    Http::assertNothingSent();
    Exceptions::assertNothingReported();
})->with([
    'caption assistant' => [fn ($test) => $test->actingAs($test->owner)->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'A post about our new tea'])],
    'alt text' => [fn ($test) => $test->actingAs($test->owner)->postJson(route('app.posts.ai.alt-text'), ['media_id' => Media::factory()->temporaryUpload($test->workspace)->create()->id])],
    'idea generator' => [fn ($test) => $test->actingAs($test->owner)->postJson(route('app.create.ideas.generate'), ['business' => 'A tea shop', 'audience' => 'Students'])],
    'item copy' => [fn ($test) => $test->actingAs($test->owner)->postJson(route('socialhq.plan.items.copy.ai', $test->item->id))],
    'brief draft' => [fn ($test) => $test->actingAs($test->owner)->postJson(route('socialhq.plan.items.brief.ai', $test->item->id))],
]);

test('a member is told to ask the owner', function () {
    $member = workspaceMember($this->workspace, 'member');

    $this->actingAs($member)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('socialhq_ai.unavailable.member'));
});

test('a company whose AI can\'t serve the task is told so', function () {
    // Platform AI allowed, but the gateway doesn't serve images or vision.
    $this->company->forceFill(['uses_platform_ai' => true])->save();
    config(['socialhq.ai.litellm.tasks' => ['write', 'structured']]);

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.alt-text'), ['media_id' => Media::factory()->temporaryUpload($this->workspace)->create()->id])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('socialhq.ai.task_unavailable.owner'));

    Exceptions::assertNothingReported();
});

test('a failing provider is reported and answered with the endpoint\'s 502', function () {
    $this->company->forceFill(['uses_platform_ai' => true])->save();
    PostWritingAssistant::fake(fn () => throw new AiException('down'));

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'A post about our new tea'])
        ->assertStatus(Response::HTTP_BAD_GATEWAY)
        ->assertJsonPath('message', __('posts.composer.assistant_error'));

    Exceptions::assertReported(AiException::class);
});

test('pages share which AI tasks the company can use', function (bool $platformAi, array $tasks) {
    $this->company->forceFill(['uses_platform_ai' => $platformAi])->save();
    config(['socialhq.ai.litellm.tasks' => ['write', 'structured']]);

    $this->actingAs($this->owner)->get(route('app.posts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('aiTasks', $tasks)->missing('aiEnabled'));
})->with([
    'no AI' => [false, ['write' => false, 'structured' => false, 'vision' => false, 'any' => false]],
    'platform AI without vision' => [true, ['write' => true, 'structured' => true, 'vision' => false, 'any' => true]],
]);

test('the item page hides AI the company can\'t use', function () {
    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page->where('aiAvailable', false));

    $this->company->forceFill(['uses_platform_ai' => true])->save();
    // A new request decides again (the decision is kept for one request).
    app()->forgetScopedInstances();

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page->where('aiAvailable', true));
});

test('a queued graphic tells whoever started it how to set AI up, without reporting', function () {
    $graphic = AiGraphic::query()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'import_id' => (string) Str::uuid(),
        'prompt' => 'A marigold background',
        'format' => 'square',
        'provider' => AiRouter::LITELLM_IMAGES,
        'status' => AiGraphic::PENDING,
    ]);

    // Not signed in, like a queue worker.
    (new GenerateAiGraphic($graphic->id))->handle();

    expect($graphic->fresh())
        ->status->toBe(AiGraphic::FAILED)
        ->reason->toBe(__('socialhq_ai.unavailable.owner'));
    Exceptions::assertNothingReported();
});
