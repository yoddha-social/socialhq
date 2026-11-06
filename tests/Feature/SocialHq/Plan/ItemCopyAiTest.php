<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
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
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'title' => 'Dashain momo offer']);
});

function copyWriterAnswer(array $overrides = []): array
{
    return [
        'caption' => "Dashain is home.\n\nFresh momos at your door.",
        'hashtags' => '#Dashain #momo momo #kathmandu, #food #nepal #festival #family',
        'script' => 'Hook: steam rising.',
        'notes' => 'Check the delivery area.',
        ...$overrides,
    ];
}

test('write with AI saves the answer as a new AI version, within the channel\'s hashtag limit', function () {
    CopyWriter::fake([copyWriterAnswer()]);
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))
        ->assertCreated()
        ->assertJsonPath('number', 1);

    $version = ItemVersion::query()->sole();

    expect($version->source)->toBe(ItemSource::Ai)
        ->and($version->created_by)->toBe($member->id)
        ->and($version->caption)->toStartWith('Dashain is home.')
        // Instagram takes at most 5; duplicates go.
        ->and($version->hashtags)->toBe('#Dashain #momo #kathmandu #food #nepal')
        // Not a video, so no script.
        ->and($version->script)->toBeNull()
        ->and($this->item->fresh()->copy_status)->toBe(CopyStatus::Draft);

    CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Dashain momo offer')
        && str_contains($prompt->agent->instructions(), 'Write the first version')
        && str_contains($prompt->agent->instructions(), 'English (en)'));
});

test('a rewrite gets the latest version, the open change requests and the event, and keeps the script for video', function () {
    CopyWriter::fake([copyWriterAnswer(['caption' => 'Shorter hook.'])]);
    $event = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'name' => 'Dashain']);
    $this->item->update(['creative' => CreativeKind::Video, 'event_id' => $event->id]);
    $first = ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'A long first try']);
    $this->item->update(['copy_status' => CopyStatus::ChangesRequested]);
    ItemReview::query()->create(['item_id' => $this->item->id, 'version_id' => $first->id, 'user_id' => $this->owner->id, 'kind' => ReviewKind::ChangesRequested, 'body' => 'Make the hook shorter']);

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertCreated()->assertJsonPath('number', 2);

    expect(ItemVersion::query()->where('number', 2)->sole()->script)->toBe('Hook: steam rising.')
        ->and($first->fresh()->caption)->toBe('A long first try');

    CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Rewrite the latest version')
        && str_contains($prompt->agent->instructions(), 'Make the hook shorter')
        && str_contains($prompt->agent->instructions(), 'A long first try')
        && str_contains($prompt->agent->instructions(), 'Dashain')
        && str_contains($prompt->agent->instructions(), 'what to show'));
});

test('a failed or empty answer saves nothing and says so', function (Closure $fake) {
    $fake();

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))
        ->assertStatus(502)
        ->assertJsonPath('message', __('socialhq_production.item.copy.ai_failed'));

    expect(ItemVersion::query()->count())->toBe(0);
})->with([
    'empty caption' => [fn () => CopyWriter::fake([copyWriterAnswer(['caption' => '  '])])],
    'provider error' => [fn () => CopyWriter::fake(fn () => throw new AiException('down'))],
    'unreadable reply' => [fn () => CopyWriter::fake(fn () => throw new RuntimeException('Syntax error in JSON'))],
]);

test('copy longer than the channel takes is fitted: hashtags first, then the caption, and the note says so', function () {
    // Instagram: 2200 characters for caption + blank line + hashtags.
    CopyWriter::fake([
        copyWriterAnswer(['caption' => str_repeat('a', 2185), 'hashtags' => '#one #two #three', 'notes' => '']),
        copyWriterAnswer(['caption' => str_repeat('b', 2300), 'hashtags' => '#one', 'notes' => 'Aimed warm.']),
    ]);

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertCreated();
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertCreated();

    [$first, $second] = ItemVersion::query()->orderBy('number')->get()->all();

    expect(mb_strlen($first->caption) + mb_strlen((string) $first->hashtags) + 2)->toBeLessThanOrEqual(2200)
        ->and($first->caption)->toBe(str_repeat('a', 2185))
        ->and($first->hashtags)->toBe('#one #two')
        ->and($first->notes)->toBe(__('socialhq_production.item.copy.ai_trimmed'))
        ->and($second->hashtags)->toBeNull()
        ->and(mb_strlen($second->caption))->toBe(2200)
        ->and($second->caption)->toEndWith('…')
        ->and($second->notes)->toStartWith('Aimed warm.');

    // The writer is told to leave room for the hashtags, and not to invent links or shops.
    CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->maxLength < 2200
        && str_contains($prompt->agent->instructions(), 'link in bio'));
});

test('AI copy is unavailable without a write provider, and never across workspaces', function () {
    CopyWriter::fake([copyWriterAnswer()]);
    $other = PlanItem::factory()->create();

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $other->id))->assertNotFound();

    config(['ai.providers.anthropic.key' => null]);
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertNotFound();

    expect(ItemVersion::query()->count())->toBe(0);
});
