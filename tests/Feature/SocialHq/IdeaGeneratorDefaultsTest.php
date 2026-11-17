<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\IdeaGeneratorDefaults;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('the idea generator starts from the brand profile', function () {
    BrandProfile::query()->create([
        'workspace_id' => $this->workspace->id,
        'about' => '  A café in Kathmandu roasting its own beans.  ',
        'audience' => 'Students and young professionals nearby.',
    ]);
    $other = Workspace::factory()->create();
    BrandProfile::query()->create(['workspace_id' => $other->id, 'about' => 'Another brand', 'audience' => 'Someone else']);

    $this->actingAs($this->user)->get(route('app.create.ideas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ideaGeneratorDefaults.business', 'A café in Kathmandu roasting its own beans.')
            ->where('ideaGeneratorDefaults.audience', 'Students and young professionals nearby.'));
});

test('without a brand profile the idea generator starts empty', function () {
    $this->actingAs($this->user)->get(route('app.create.ideas.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ideaGeneratorDefaults.business', '')
            ->where('ideaGeneratorDefaults.audience', ''));
});

test('a long profile field is cut to what the generator accepts', function () {
    BrandProfile::query()->create([
        'workspace_id' => $this->workspace->id,
        'about' => str_repeat('a', IdeaGeneratorDefaults::MAX_LENGTH + 200),
        'audience' => 'Everyone',
    ]);

    $business = IdeaGeneratorDefaults::for($this->workspace)['business'];

    expect(mb_strlen($business))->toBeLessThanOrEqual(IdeaGeneratorDefaults::MAX_LENGTH)
        ->and($business)->toEndWith('…');
});
