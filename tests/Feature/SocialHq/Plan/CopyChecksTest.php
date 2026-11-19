<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\Ai\UnsupportedDetails;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\Placeholders;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefStage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * QA 2026-11-18 P1-2 and P1-4: copy with gaps can't be approved or posted,
 * what the AI invents is flagged, and an approver can let a post go out
 * text-only.
 */

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
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create([
        'plan_id' => $plan->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform->value,
        'content_type' => ContentType::LinkedInPost,
        'title' => 'Gift from Sydney',
        'angle' => 'A customer in Sydney sends a Dhaka tote home. No names, no quotes.',
        'creative' => CreativeKind::Image,
    ]);
    $this->writer = workspaceMember($this->workspace, 'approval');
});

test('placeholders are square-bracket gaps, not markdown links', function () {
    expect(Placeholders::in('Made in [city] by [weaver name].', '#tote', null))->toBe(['[city]', '[weaver name]'])
        // Networks post a markdown link as typed, brackets included.
        ->and(Placeholders::in('See [our shop](https://example.test).'))->toBe(['[our shop]'])
        ->and(Placeholders::in('[City] and [city]'))->toBe(['[City]'])
        ->and(Placeholders::in('No gaps here.'))->toBe([]);
});

test('shot directions in a video script are not gaps', function () {
    $this->item->update(['creative' => CreativeKind::Video]);
    $this->actingAs($this->writer)->post(route('socialhq.plan.items.versions.store', $this->item->id), [
        'caption' => 'Sent home with love.', 'hashtags' => '#dhaka', 'script' => "[Close-up of the loom]\nVoice: woven in Palpa.", 'notes' => null,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->writer)->post(route('socialhq.plan.items.copy.submit', $this->item->id));

    $version = ItemVersion::query()->where('item_id', $this->item->id)->sole();

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.approve', $this->item->id), ['version_id' => $version->id])
        ->assertSessionHasNoErrors();

    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::Approved);
});

test('copy with gaps in square brackets cannot be approved', function () {
    $this->actingAs($this->writer)->post(route('socialhq.plan.items.versions.store', $this->item->id), [
        'caption' => 'Sent home from [customer city] with love.', 'hashtags' => '#dhaka', 'script' => null, 'notes' => null,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->writer)->post(route('socialhq.plan.items.copy.submit', $this->item->id))->assertSessionHasNoErrors();

    $version = ItemVersion::query()->where('item_id', $this->item->id)->sole();

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.approve', $this->item->id), ['version_id' => $version->id])
        ->assertSessionHasErrors(['copy' => __('socialhq_production.item.copy.errors.placeholders', ['gaps' => '[customer city]'])]);

    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::InReview);

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page->where('versions.0.placeholders', ['[customer city]']));
});

test('every blocker shows at once, and gaps keep approved copy from becoming a post', function () {
    Brief::factory()->for($this->item, 'item')->create(['stage' => BriefStage::InReview]);
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'From [city], with love.']);

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('postReady', false)
            ->where('postBlockers', ['copy', 'placeholders', 'creative'])
            ->where('creativeWaiver', null));

    // Older copy approved before this check.
    $this->item->update(['copy_status' => CopyStatus::Approved]);
    $this->item->brief->update(['stage' => BriefStage::Approved]);

    $this->actingAs($this->writer)->postJson(route('socialhq.plan.items.post.store', $this->item->id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.post.0', __('socialhq_production.post.errors.placeholders', ['gaps' => '[city]']));
});

test('an approver lets the post go out text-only, and can take it back', function () {
    Brief::factory()->for($this->item, 'item')->create(['stage' => BriefStage::InProgress]);
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'Sent home with love.', 'hashtags' => null]);
    $this->item->update(['copy_status' => CopyStatus::Approved]);

    $this->actingAs($this->writer)->postJson(route('socialhq.plan.items.post.store', $this->item->id))->assertUnprocessable();

    // Members can't waive the creative.
    $this->actingAs($this->writer)->post(route('socialhq.plan.items.post.text-only.store', $this->item->id))->assertForbidden();

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.post.text-only.store', $this->item->id))->assertSessionHasNoErrors();

    $item = $this->item->fresh();
    expect($item->creative_waived_at)->not->toBeNull()
        ->and($item->creative_waived_by)->toBe($this->owner->id);

    $this->actingAs($this->writer)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('postReady', true)
            ->where('postBlockers', [])
            ->where('creativeWaiver.by.id', $this->owner->id));

    // The writer creates the post: copy only, no files.
    $this->actingAs($this->writer)->postJson(route('socialhq.plan.items.post.store', $this->item->id))
        ->assertOk()
        ->assertJsonPath('content', 'Sent home with love.')
        ->assertJsonCount(0, 'media');

    $this->actingAs($this->owner)->delete(route('socialhq.plan.items.post.text-only.destroy', $this->item->id))->assertSessionHasNoErrors();

    expect($this->item->fresh()->creative_waived_at)->toBeNull();
});

test('an item with nothing to make has no creative to waive', function () {
    $this->item->update(['creative' => CreativeKind::None]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.post.text-only.store', $this->item->id))
        ->assertSessionHasErrors('creative');
});

test('AI copy flags the names, numbers and quotes its sources do not have', function () {
    CopyWriter::fake([[
        'caption' => "Meet our weaver Sita.\n\nShe spends three days on every tote, and our customer in Sydney said \"it feels like home in my hands\". Rs 2,400, delivered.",
        'hashtags' => '#dhaka #tote2027',
        'script' => '',
        'notes' => '',
    ]]);

    $this->actingAs($this->writer)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertCreated();

    $version = ItemVersion::query()->where('item_id', $this->item->id)->sole();

    expect($version->source)->toBe(ItemSource::Ai)
        ->and($version->unsupported_details)->toContain('Sita', 'three', '2,400', '“it feels like home in my hands”')
        // In the item's angle: not flagged.
        ->and($version->unsupported_details)->not->toContain('Sydney')
        ->and($version->unsupported_details)->not->toContain('Meet');

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page->where('versions.0.unsupported_details', $version->unsupported_details));
});

test('the check counts a detail as given when any source has it', function () {
    $sources = 'Dhaka tote bag: Rs 2,400, handwoven in Palpa. 60% of followers are in Australia. Event: Maghe Sankranti, 2027-01-15.';

    expect(UnsupportedDetails::find('Our Dhaka tote, handwoven in Palpa, is Rs 2400. Happy Maghe Sankranti on 15 January!', $sources))->toBe([])
        ->and(UnsupportedDetails::find('Most of you (60%) live in Australia.', $sources))->toBe([])
        ->and(UnsupportedDetails::find('Celebrate the Tharu new year with us.', $sources))->toBe(['Tharu'])
        ->and(UnsupportedDetails::find('Made by 12 weavers in Palpa.', $sources))->toBe(['12'])
        ->and(UnsupportedDetails::find('', $sources))->toBe([])
        // A name after an emoji, a bullet or a line break starts a sentence; mid-sentence it doesn't.
        ->and(UnsupportedDetails::find("🧵 Woven in Palpa.\n• Maghe Sankranti\nAsk Bina.", $sources))->toBe(['Bina'])
        // Hashtags and handles are not names or numbers.
        ->and(UnsupportedDetails::find('Shop the #Tote2027 with @DhakaHouse.', $sources))->toBe([])
        // Numbering is not a claim.
        ->and(UnsupportedDetails::find("Slide 3: the lining.\n4. The handles.\nPart 7 of the series, 1/9.", $sources))->toBe([])
        // A hyphenated name counts when each part is in the sources.
        ->and(UnsupportedDetails::find('Celebrate with Bhai-Tika wishes and Coca-Cola.', 'Bhai Tika is on Friday.'))->toBe(['Coca-Cola'])
        // A short quoted word never pairs with the next quote's opening mark.
        ->and(UnsupportedDetails::find('Our "Dhaka" tote is what customers call "the gift of home here".', $sources))->toBe(['“the gift of home here”']);
});

test('sources built from the context read line breaks as spaces', function () {
    $sources = UnsupportedDetails::sourceText(['open_change_requests' => ["Please mention:\nSita, our weaver"]], 'Brand text');

    expect(UnsupportedDetails::find('Woven by our weaver Sita.', $sources))->toBe([]);
});

test('a person\'s edit keeps the AI\'s flags while the text still says them, and approval clears them', function () {
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'Woven by Sita in three days.', 'source' => ItemSource::Ai, 'unsupported_details' => ['Sita', 'three']]);

    $this->actingAs($this->writer)->post(route('socialhq.plan.items.versions.store', $this->item->id), [
        'caption' => 'Woven by Sita in Palpa.', 'hashtags' => '#dhaka', 'script' => null, 'notes' => null,
    ])->assertSessionHasNoErrors();

    $edited = ItemVersion::query()->where('item_id', $this->item->id)->where('number', 2)->sole();
    expect($edited->unsupported_details)->toBe(['Sita']);

    $this->actingAs($this->writer)->post(route('socialhq.plan.items.copy.submit', $this->item->id));
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.approve', $this->item->id), ['version_id' => $edited->id])->assertSessionHasNoErrors();

    $this->actingAs($this->writer)->post(route('socialhq.plan.items.versions.store', $this->item->id), [
        'caption' => 'Woven by Sita in Palpa, now in blue.', 'hashtags' => '#dhaka', 'script' => null, 'notes' => null,
    ])->assertSessionHasNoErrors();

    expect(ItemVersion::query()->where('item_id', $this->item->id)->where('number', 3)->sole()->unsupported_details)->toBeNull();
});

test('a text-only waiver ends when the item\'s creative, channel or format changes', function () {
    $this->item->forceFill(['creative_waived_at' => now(), 'creative_waived_by' => $this->owner->id])->save();
    $item = $this->item->fresh();

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $item->id), [
        'scheduled_on' => $item->scheduled_on->toDateString(),
        'social_account_id' => $item->social_account_id,
        'content_type' => $item->content_type->value,
        'creative' => CreativeKind::None->value,
        'title' => $item->title,
        'objective' => $item->objective,
        'angle' => $item->angle,
    ])->assertSessionHasNoErrors();

    expect($this->item->fresh()->creative_waived_at)->toBeNull()
        ->and($this->item->fresh()->creative_waived_by)->toBeNull();
});

test('editing an AI-planned item keeps the flags its text still carries', function () {
    $this->item->update(['angle' => 'Maya and Raj from Sydney share their tote.', 'unsupported_details' => ['Maya', 'Raj']]);
    $item = $this->item->fresh();

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $item->id), [
        'scheduled_on' => $item->scheduled_on->toDateString(),
        'social_account_id' => $item->social_account_id,
        'content_type' => $item->content_type->value,
        'creative' => $item->creative->value,
        'title' => $item->title,
        'objective' => $item->objective,
        'angle' => 'Maya from Sydney shares her tote.',
    ])->assertSessionHasNoErrors();

    expect($this->item->fresh()->unsupported_details)->toBe(['Maya']);
});

test('a person\'s earlier version is a source for the rewrite, an AI version is not', function () {
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'Our weaver Sita made this.', 'source' => ItemSource::Manual]);
    CopyWriter::fake([['caption' => 'Woven for you by Sita.', 'hashtags' => '#dhaka', 'script' => '', 'notes' => '']]);

    $this->actingAs($this->writer)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertCreated();

    expect(ItemVersion::query()->where('item_id', $this->item->id)->where('number', 2)->sole()->unsupported_details)->toBeNull();

    CopyWriter::fake([['caption' => 'Woven for you by Sita and Gita.', 'hashtags' => '#dhaka', 'script' => '', 'notes' => '']]);

    $this->actingAs($this->writer)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))->assertCreated();

    // Version 2 was the AI's, so its "Sita" no longer counts; version 1 is not in the context.
    expect(ItemVersion::query()->where('item_id', $this->item->id)->where('number', 3)->sole()->unsupported_details)->toBe(['Sita', 'Gita']);
});
