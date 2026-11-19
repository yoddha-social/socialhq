<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Mcp\Tools\Plan\GetBrandKnowledgeTool;
use App\SocialHq\Mcp\Tools\Plan\GetContentPlanTool;
use App\SocialHq\Mcp\Tools\Plan\GetPlanItemTool;
use App\SocialHq\Mcp\Tools\Plan\ListPlanEventsTool;
use App\SocialHq\Mcp\Tools\Plan\SavePlanItemCopyTool;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->month = $this->plan->month->format('Y-m');
    $this->item = PlanItem::factory()->create(['plan_id' => $this->plan->id, 'owner_id' => $this->owner->id, 'title' => 'Dashain momo offer']);
});

test('get-content-plan-tool reads the month\'s plan and items of this brand only', function () {
    $otherPlan = ContentPlan::factory()->create(['month' => $this->plan->month->toDateString()]);
    PlanItem::factory()->create(['plan_id' => $otherPlan->id, 'title' => 'Another brand']);

    TryPostServer::actingAs($this->owner)->tool(GetContentPlanTool::class, ['month' => $this->month])
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('month', $this->month)
            ->where('plan.status', 'draft')
            ->has('items', 1)
            ->where('items.0.id', $this->item->id)
            ->where('items.0.title', 'Dashain momo offer')
            ->where('items.0.owner', $this->owner->name)
            ->etc());

    TryPostServer::actingAs($this->owner)->tool(GetContentPlanTool::class, ['month' => $this->plan->month->subMonth()->format('Y-m')])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('plan', null)->has('items', 0)->etc());
});

test('list-plan-events-tool lists the month\'s events with whether a person confirmed them', function () {
    $day = $this->plan->month->addDays(4)->toDateString();
    CalendarEvent::factory()->verified($this->owner)->create(['account_id' => $this->workspace->account_id, 'name' => 'Dashain', 'starts_on' => $day, 'ends_on' => $day]);
    CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'name' => 'Unchecked', 'starts_on' => $day, 'ends_on' => $day]);
    CalendarEvent::factory()->create(['name' => 'Other company', 'starts_on' => $day, 'ends_on' => $day]);

    TryPostServer::actingAs($this->owner)->tool(ListPlanEventsTool::class, ['month' => $this->month])
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('events', fn ($events) => collect($events)->mapWithKeys(fn (array $event): array => [$event['name'] => $event['confirmed']])->all() === ['Dashain' => true, 'Unchecked' => false])
            ->etc());
});

test('get-plan-item-tool reads the latest copy, open change requests and the thread', function () {
    $version = ItemVersion::factory()->for($this->item, 'item')->create(['number' => 1, 'caption' => 'First draft', 'created_by' => $this->owner->id]);
    $this->item->update(['copy_status' => CopyStatus::ChangesRequested]);
    ItemReview::query()->create(['item_id' => $this->item->id, 'version_id' => $version->id, 'user_id' => $this->owner->id, 'kind' => ReviewKind::ChangesRequested, 'body' => 'Add the price']);

    TryPostServer::actingAs($this->owner)->tool(GetPlanItemTool::class, ['item_id' => $this->item->id])
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('latest_copy.caption', 'First draft')
            ->where('copy_status', 'changes_requested')
            ->where('open_change_requests', ['Add the price'])
            ->where('thread.0.kind', 'changes_requested')
            ->etc());

    $foreign = PlanItem::factory()->create();
    TryPostServer::actingAs($this->owner)->tool(GetPlanItemTool::class, ['item_id' => $foreign->id])->assertHasErrors(['Plan item not found.']);
});

test('save-plan-item-copy-tool adds an AI version in draft and accepts the suggestion it belongs to', function () {
    $this->item->update(['status' => ItemStatus::Suggested]);

    TryPostServer::actingAs($this->owner)->tool(SavePlanItemCopyTool::class, ['item_id' => $this->item->id, 'caption' => 'Momos for Dashain', 'hashtags' => '#dashain'])
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('copy_status', 'draft')->where('latest_copy.number', 1)->where('latest_copy.source', 'ai')->etc());

    $item = $this->item->fresh();
    expect($item->status)->toBe(ItemStatus::Planned)
        ->and($item->latestVersion->source)->toBe(ItemSource::Ai)
        ->and($item->latestVersion->created_by)->toBe($this->owner->id);
});

test('save-plan-item-copy-tool flags what the item and brand knowledge don\'t say, and reports gaps', function () {
    TryPostServer::actingAs($this->owner)->tool(SavePlanItemCopyTool::class, ['item_id' => $this->item->id, 'caption' => 'Momos from our chef Ramesh, made in [city].'])
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('latest_copy.placeholders', ['[city]'])
            ->where('latest_copy.unsupported_details', ['Ramesh'])
            ->etc());

    expect($this->item->fresh()->latestVersion->unsupported_details)->toBe(['Ramesh']);
});

test('save-plan-item-copy-tool never replaces copy that is in review or approved', function (CopyStatus $status) {
    ItemVersion::factory()->for($this->item, 'item')->create(['number' => 1, 'caption' => 'Reviewed', 'created_by' => $this->owner->id]);
    $this->item->update(['copy_status' => $status]);

    TryPostServer::actingAs($this->owner)->tool(SavePlanItemCopyTool::class, ['item_id' => $this->item->id, 'caption' => 'Sneaky rewrite'])
        ->assertHasErrors([__('socialhq.agents.copy_under_review')]);

    expect(ItemVersion::query()->where('item_id', $this->item->id)->count())->toBe(1)
        ->and($this->item->fresh()->copy_status)->toBe($status);
})->with([CopyStatus::InReview, CopyStatus::Approved]);

test('get-brand-knowledge-tool returns what the AI writers read', function () {
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'about' => 'A momo shop in Kathmandu.']);

    TryPostServer::actingAs($this->owner)->tool(GetBrandKnowledgeTool::class, ['topic' => 'Dashain'])
        ->assertHasNoErrors()
        ->assertSee('A momo shop in Kathmandu.');
});

test('an admin\'s mcp grant writes copy and sends it for review', function () {
    Queue::fake();
    config(['socialhq.approvals.members_always_reviewed' => true, 'socialhq.approvals.agent_posts_reviewed' => true]);
    $token = mcpBearerToken($this->owner, $this->workspace)['plain_token'];
    $call = fn (string $tool, array $arguments) => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])
        ->postJson(route('mcp.trypost'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
        ->assertOk();

    $call('save-plan-item-copy-tool', ['item_id' => $this->item->id, 'caption' => 'Momos for Dashain'])->assertJsonPath('result.isError', false);
    $call('submit-plan-item-copy-tool', ['item_id' => $this->item->id])
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.copy_status', 'in_review');

    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::InReview)
        ->and(ItemReview::query()->where('item_id', $this->item->id)->where('kind', ReviewKind::Submitted)->exists())->toBeTrue();
});
