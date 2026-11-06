<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\KnowledgeEntry;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

function knowledgeEntry(Workspace $workspace, array $attributes = []): KnowledgeEntry
{
    return KnowledgeEntry::query()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'product',
        'title' => 'Momo Express',
        'body' => 'Delivery in 30 minutes inside the Ring Road.',
        'use_in_ai' => true,
        ...$attributes,
    ]);
}

test('an admin saves the brand profile and manages knowledge', function () {
    $this->actingAs($this->owner)
        ->put(route('socialhq.brand.profile.update'), [
            'about' => 'A Kathmandu momo chain.',
            'audience' => 'Students and office workers.',
            'voice' => 'Warm and playful.',
            'words_to_use' => 'fresh, local',
            'words_to_avoid' => 'cheap',
        ])
        ->assertRedirect();

    expect(BrandProfile::query()->where('workspace_id', $this->workspace->id)->value('voice'))->toBe('Warm and playful.');

    $this->actingAs($this->owner)
        ->post(route('socialhq.brand.entries.store'), ['kind' => 'project', 'title' => 'Dashain campaign', 'body' => 'Free delivery all week.', 'use_in_ai' => true])
        ->assertRedirect();
    $entry = KnowledgeEntry::query()->where('workspace_id', $this->workspace->id)->firstOrFail();

    $this->actingAs($this->owner)
        ->put(route('socialhq.brand.entries.update', $entry->id), ['kind' => 'campaign', 'title' => 'Dashain campaign', 'body' => 'Free delivery all week.', 'use_in_ai' => false])
        ->assertRedirect();
    expect($entry->fresh()->use_in_ai)->toBeFalse();

    $this->actingAs($this->owner)
        ->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->component('socialhq/BrandKnowledge')
            ->where('canEdit', true)
            ->where('profile.about', 'A Kathmandu momo chain.')
            ->has('entries', 1)
            ->where('entries.0.kind', 'campaign'));

    $this->actingAs($this->owner)->delete(route('socialhq.brand.entries.destroy', $entry->id))->assertRedirect();
    expect(KnowledgeEntry::query()->count())->toBe(0);
});

test('members read the brand knowledge but cannot change it', function () {
    $entry = knowledgeEntry($this->workspace);
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->where('canEdit', false)->has('entries', 1));
    $this->actingAs($member)->put(route('socialhq.brand.profile.update'), ['about' => 'Changed'])->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.brand.entries.store'), ['kind' => 'fact', 'title' => 'X', 'body' => 'Y', 'use_in_ai' => true])->assertForbidden();
    $this->actingAs($member)->delete(route('socialhq.brand.entries.destroy', $entry->id))->assertForbidden();
});

test('another workspace\'s entries cannot be changed', function () {
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $entry = knowledgeEntry($other);

    $this->actingAs($this->owner)
        ->put(route('socialhq.brand.entries.update', $entry->id), ['kind' => 'fact', 'title' => 'Hijack', 'body' => 'No', 'use_in_ai' => true])
        ->assertNotFound();
    $this->actingAs($this->owner)->delete(route('socialhq.brand.entries.destroy', $entry->id))->assertNotFound();

    expect($entry->fresh()->title)->toBe('Momo Express');
});

test('a workspace stops at its knowledge limit', function () {
    config(['socialhq.knowledge.max_entries' => 1]);
    knowledgeEntry($this->workspace);

    $this->actingAs($this->owner)
        ->post(route('socialhq.brand.entries.store'), ['kind' => 'fact', 'title' => 'Second', 'body' => 'Too many.', 'use_in_ai' => true])
        ->assertSessionHasErrors('title');
});

test('the prompt holds the profile and the newest entries marked for AI that fit the budget', function () {
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'about' => 'A Kathmandu momo chain.', 'words_to_avoid' => 'cheap']);
    knowledgeEntry($this->workspace, ['title' => 'Hidden draft', 'use_in_ai' => false]);
    $this->travel(-2)->days();
    knowledgeEntry($this->workspace, ['title' => 'Old fact', 'body' => str_repeat('long ', 200)]);
    $this->travelBack();
    knowledgeEntry($this->workspace, ['title' => 'Momo Express']);

    config(['socialhq.knowledge.prompt_characters' => 500]);
    $prompt = BrandKnowledge::forPrompt($this->workspace);

    expect($prompt)
        ->toContain("What the brand is:\nA Kathmandu momo chain.")
        ->toContain("Words and phrases to avoid:\ncheap")
        ->toContain('- Product or service: Momo Express')
        ->not->toContain('Hidden draft')
        ->not->toContain('Old fact')
        ->and(BrandKnowledge::forPrompt(Workspace::factory()->create()))->toBe('');
});

test('the caption assistant and the idea generator write from the current workspace\'s brand', function () {
    knowledgeEntry($this->workspace);
    $this->actingAs($this->owner);

    $assistant = (new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::DEFAULT))->instructions();
    $ideas = (new IdeaGenerator(Locale::DEFAULT, 'A bakery', 'Families'))->instructions();

    expect($assistant)->toContain('What the team knows about this brand')->toContain('Momo Express')
        ->and($ideas)->toContain('What the team knows about this brand')->toContain('Momo Express');

    KnowledgeEntry::query()->delete();

    expect((new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::DEFAULT))->instructions())
        ->not->toContain('What the team knows about this brand');
});
