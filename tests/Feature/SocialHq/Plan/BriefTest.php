<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use App\SocialHq\Production\Ai\BriefWriter;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefRounds;
use App\SocialHq\Production\BriefStage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.write' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'creative' => CreativeKind::Image, 'content_type' => ContentType::InstagramFeed]);
});

test('a member creates the brief of an item that needs a creative, with the format filled in', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)->post(route('socialhq.plan.items.brief.store', $this->item->id))->assertSessionHasNoErrors();
    // A second click changes nothing.
    $this->actingAs($member)->post(route('socialhq.plan.items.brief.store', $this->item->id))->assertSessionHasNoErrors();

    $brief = Brief::query()->sole();

    expect($brief->item_id)->toBe($this->item->id)
        ->and($brief->workspace_id)->toBe($this->workspace->id)
        ->and($brief->kind)->toBe(BriefKind::Graphic)
        ->and($brief->stage)->toBe(BriefStage::Todo)
        ->and($brief->format_notes)->toContain('1080 × 1350')
        ->and($brief->format_notes)->toContain('4:5');

    $this->actingAs($member)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('brief.id', $brief->id)
            ->where('brief.stage', 'todo')
            ->where('briefKind', 'graphic')
            ->has('team', 2));
});

test('a video item gets a video brief, and an item without a creative gets none', function () {
    $this->item->update(['creative' => CreativeKind::Video, 'content_type' => ContentType::InstagramReel]);
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.store', $this->item->id));

    expect(Brief::query()->sole()->kind)->toBe(BriefKind::Video)
        ->and(Brief::query()->sole()->format_notes)->toContain('9:16');

    $plain = PlanItem::factory()->create(['plan_id' => $this->item->plan_id, 'creative' => CreativeKind::None]);
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.store', $plain->id))->assertSessionHasErrors('brief');

    expect(Brief::query()->count())->toBe(1);
});

test('the brief is updated with an assignee from the workspace only', function () {
    $designer = workspaceMember($this->workspace, 'member');
    $stranger = User::factory()->create();
    $brief = Brief::factory()->for($this->item, 'item')->create();
    $due = now()->addWeek()->toDateString();

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.brief.update', $this->item->id), [
        'kind' => 'graphic',
        'assignee_id' => $stranger->id,
    ])->assertSessionHasErrors('assignee_id');

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.brief.update', $this->item->id), [
        'kind' => 'graphic',
        'assignee_id' => $designer->id,
        'due_on' => $due,
        'format_notes' => '1080 × 1350',
        'on_screen_text' => 'Happy Dashain',
        'direction' => 'Warm marigold tones',
        'references' => "https://example.com/a\nhttps://example.com/b",
    ])->assertSessionHasNoErrors();

    $brief->refresh();

    expect($brief->assignee_id)->toBe($designer->id)
        ->and($brief->due_on->toDateString())->toBe($due)
        ->and($brief->on_screen_text)->toBe('Happy Dashain');
});

test('a brief of another workspace is not found', function () {
    $other = Brief::factory()->create();

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.brief.update', $other->item_id), ['kind' => 'graphic'])->assertNotFound();
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.store', $other->item_id))->assertNotFound();
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.brief.ai', $other->item_id))->assertNotFound();
});

test('draft brief with AI returns a draft from the copy without saving it', function () {
    BriefWriter::fake([['on_screen_text' => 'Happy Dashain', 'direction' => 'Marigold and brass', 'references' => 'Last year\'s Tihar post']]);
    $brief = Brief::factory()->for($this->item, 'item')->create(['direction' => 'Keep the logo small']);
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'Momos for the whole family this Dashain']);

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.brief.ai', $this->item->id))
        ->assertOk()
        ->assertJson(['on_screen_text' => 'Happy Dashain', 'direction' => 'Marigold and brass', 'references' => 'Last year\'s Tihar post']);

    expect($brief->fresh()->direction)->toBe('Keep the logo small')
        ->and($brief->fresh()->on_screen_text)->toBeNull();

    BriefWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Momos for the whole family this Dashain')
        && str_contains($prompt->agent->instructions(), 'Keep the logo small')
        && str_contains($prompt->agent->instructions(), 'graphic designer'));
});

test('a failed brief draft says so', function () {
    BriefWriter::fake(fn () => throw new AiException('down'));
    Brief::factory()->for($this->item, 'item')->create();

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.brief.ai', $this->item->id))
        ->assertStatus(502)
        ->assertJsonPath('message', __('socialhq_production.brief.ai_failed'));
});

test('a brief keeps the kind its item needs', function () {
    Brief::factory()->for($this->item, 'item')->create(['kind' => BriefKind::Graphic]);

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.brief.update', $this->item->id), ['kind' => 'video'])
        ->assertSessionHasErrors(['kind' => __('socialhq_production.brief.errors.kind_fixed')]);

    expect($this->item->brief->fresh()->kind)->toBe(BriefKind::Graphic);
});

test('changing what an approved creative must show reopens it for a new round', function () {
    $brief = Brief::factory()->for($this->item, 'item')->create(['kind' => BriefKind::Graphic, 'stage' => BriefStage::Approved, 'approved_at' => now(), 'approved_by' => $this->owner->id, 'on_screen_text' => 'Happy Dashain']);
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);

    // A new due date or assignee leaves it approved.
    $this->actingAs($this->owner)->put(route('socialhq.plan.items.brief.update', $this->item->id), ['kind' => 'graphic', 'on_screen_text' => 'Happy Dashain', 'due_on' => now()->addWeek()->toDateString()])->assertSessionHasNoErrors();
    expect($brief->fresh()->stage)->toBe(BriefStage::Approved);

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.brief.update', $this->item->id), ['kind' => 'graphic', 'on_screen_text' => 'Happy Tihar'])->assertSessionHasNoErrors();

    $brief->refresh();
    expect($brief->stage)->toBe(BriefStage::InProgress)
        ->and($brief->approved_at)->toBeNull()
        ->and(ItemReview::query()->where('brief_id', $brief->id)->sole()->kind)->toBe(ReviewKind::Reopened)
        ->and(BriefRounds::uploadRound($brief))->toBe(2);
});
