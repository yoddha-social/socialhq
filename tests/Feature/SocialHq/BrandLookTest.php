<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\AiGraphics\AiGraphic;
use App\SocialHq\AiGraphics\GraphicSafetyReviewer;
use App\SocialHq\Knowledge\Look\BrandLook;
use App\SocialHq\Knowledge\Look\LogoStorage;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Ai\BriefWriter;
use App\SocialHq\Production\Brief;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ImagePrompt;

beforeEach(function () {
    Storage::fake(LogoStorage::DISK);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

function saveLook(User $user, array $look = []): TestResponse
{
    return test()->actingAs($user)->put(route('socialhq.brand.look.update'), [
        'colours' => [['name' => 'Navy', 'hex' => '#0B1F3A'], ['name' => 'Marigold', 'hex' => '#f5a623']],
        'heading_font' => 'Poppins',
        'body_font' => 'Inter',
        'style_notes' => 'Warm and bright, real photos of our team.',
        ...$look,
    ]);
}

test('an admin saves colours, fonts and style notes, and the page shows them', function () {
    saveLook($this->owner)->assertSessionHasNoErrors()->assertRedirect();

    $look = BrandLook::of($this->workspace);
    expect($look->colourList())->toBe([['name' => 'Navy', 'hex' => '#0b1f3a'], ['name' => 'Marigold', 'hex' => '#f5a623']])
        ->and($look->heading_font)->toBe('Poppins')
        ->and($look->promptText())->toContain('Brand colours: Navy #0b1f3a, Marigold #f5a623')
        ->toContain('Fonts: headings Poppins; body text Inter')
        ->toContain('Warm and bright');

    $this->get(route('socialhq.brand.index'))->assertInertia(fn ($page) => $page
        ->where('look.colours.0.hex', '#0b1f3a')
        ->where('look.style_notes', 'Warm and bright, real photos of our team.')
        ->where('look.logo', null));
});

test('colours must be hex values, at most eight', function () {
    saveLook($this->owner, ['colours' => [['name' => 'Red', 'hex' => 'red']]])->assertSessionHasErrors('colours.0.hex');
    saveLook($this->owner, ['colours' => array_fill(0, 9, ['name' => 'Teal', 'hex' => '#14b8a6'])])->assertSessionHasErrors('colours');

    expect(BrandLook::of($this->workspace))->toBeNull();
});

test('a PNG logo is stored on the private disk and served to members only', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 200, 120)])
        ->assertSessionHasNoErrors();

    $look = BrandLook::of($this->workspace);
    expect($look->logo_mime)->toBe('image/png')
        ->and($look->logo_width)->toBe(200)
        ->and($look->hasRasterLogo())->toBeTrue()
        ->and(Storage::disk(LogoStorage::DISK)->exists($look->logo_path))->toBeTrue()
        ->and($look->logo_path)->toStartWith("socialhq/brand/{$this->workspace->id}/");

    $member = workspaceMember($this->workspace);
    $this->actingAs($member)->get(route('socialhq.brand.look.logo.show'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs(workspaceOutsider($this->workspace))->get(route('socialhq.brand.look.logo.show'))->assertForbidden();
});

test('an SVG logo is stored without scripts, handlers or links out, and served with a locked-down policy', function () {
    $svg = <<<'SVG'
        <?xml version="1.0"?>
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)">
          <script>alert(document.cookie)</script>
          <style>.a{fill:#0b1f3a}</style>
          <style>@import url(https://evil.example/x.css);</style>
          <foreignObject><div>hi</div></foreignObject>
          <a href="https://evil.example"><rect width="5" height="5"/></a>
          <image href="https://evil.example/track.png" width="1" height="1"/>
          <use xlink:href="https://evil.example/sprite.svg#x"/>
          <path class="a" d="M0 0h10v10z" onclick="steal()" style="fill:url(https://evil.example/p)"/>
          <circle cx="5" cy="5" r="2" fill="url(#g)"/>
        </svg>
        SVG;

    $this->actingAs($this->owner)
        ->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)])
        ->assertSessionHasNoErrors();

    $look = BrandLook::of($this->workspace);
    $stored = (string) Storage::disk(LogoStorage::DISK)->get($look->logo_path);

    expect($look->logo_mime)->toBe('image/svg+xml')
        ->and($look->hasRasterLogo())->toBeFalse()
        ->and($stored)->toContain('<path')->toContain('d="M0 0h10v10z"')->toContain('.a{fill:#0b1f3a}')->toContain('fill="url(#g)"')
        ->not->toContain('script')->not->toContain('onload')->not->toContain('onclick')->not->toContain('evil.example')
        ->not->toContain('foreignObject')->not->toContain('<a ')->not->toContain('<image');

    $this->get(route('socialhq.brand.look.logo.show'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
});

test('a file that is not an image, or an SVG with entities, is refused', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->createWithContent('logo.png', '<html><script>alert(1)</script></html>')])
        ->assertSessionHasErrors('logo');

    $entities = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';
    $this->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->createWithContent('logo.svg', $entities)])
        ->assertSessionHasErrors('logo');

    expect(BrandLook::of($this->workspace))->toBeNull()
        ->and(Storage::disk(LogoStorage::DISK)->allFiles())->toBe([]);
});

test('members see the brand look but only admins change it', function () {
    $member = workspaceMember($this->workspace);

    saveLook($member)->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertForbidden();
    $this->actingAs($member)->delete(route('socialhq.brand.look.logo.destroy'))->assertForbidden();

    expect(BrandLook::of($this->workspace))->toBeNull();
});

test('replacing or removing the logo deletes the old file, and deleting the workspace removes its logo', function () {
    $this->actingAs($this->owner)->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->image('one.png', 64, 64)]);
    $first = BrandLook::of($this->workspace)->logo_path;

    $this->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->image('two.jpg', 64, 64)]);
    $second = BrandLook::of($this->workspace)->logo_path;

    expect(Storage::disk(LogoStorage::DISK)->exists($first))->toBeFalse()
        ->and(Storage::disk(LogoStorage::DISK)->exists($second))->toBeTrue();

    $this->delete(route('socialhq.brand.look.logo.destroy'))->assertRedirect();
    expect(Storage::disk(LogoStorage::DISK)->exists($second))->toBeFalse()
        ->and(BrandLook::of($this->workspace)->hasLogo())->toBeFalse();

    $this->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->image('three.png', 64, 64)]);
    $third = BrandLook::of($this->workspace)->logo_path;

    $this->workspace->delete();

    expect(Storage::disk(LogoStorage::DISK)->exists($third))->toBeFalse();
});

test('the brief writer gets the brand look, and the copy writer the style line', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.write' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);
    saveLook($this->owner);
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id, 'title' => 'Dashain offer']);
    Brief::factory()->for($item, 'item')->create();
    ItemVersion::factory()->for($item, 'item')->create(['caption' => 'Fresh for Dashain']);

    BriefWriter::fake([['on_screen_text' => 'Happy Dashain', 'direction' => 'Navy background', 'references' => '']]);
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.brief.ai', $item->id))->assertOk();

    BriefWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Brand colours: Navy #0b1f3a, Marigold #f5a623')
        && str_contains($prompt->agent->instructions(), 'Fonts: headings Poppins'));

    CopyWriter::fake([['caption' => 'Fresh.', 'hashtags' => '', 'script' => '', 'notes' => '']]);
    $this->postJson(route('socialhq.plan.items.copy.ai', $item->id))->assertCreated();

    CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), 'Warm and bright, real photos of our team.')
        && ! str_contains($prompt->agent->instructions(), '#0b1f3a'));
});

test('AI graphics can add the brand logo as the first reference and follow the brand look', function () {
    config([
        'socialhq.ai_graphics.enabled' => true,
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['gemini', 'anthropic'],
        'socialhq.ai.routes.image' => 'gemini',
        'socialhq.ai.routes.vision' => 'anthropic',
        'socialhq.ai_graphics.vision_check' => true,
        'ai.providers.gemini.key' => 'test-gemini-key',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);
    saveLook($this->owner);
    $this->post(route('socialhq.brand.look.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 120, 120)]);
    GraphicSafetyReviewer::fake(array_fill(0, 2, ['allowed' => true, 'reason' => '']));
    Image::fake([base64_encode(UploadedFile::fake()->image('generated.png', 96, 96)->getContent())]);

    $this->getJson(route('socialhq.ai-graphics.index'))->assertOk()->assertJsonPath('brand.look', true)->assertJsonPath('brand.vectorLogo', false);

    $importId = $this->postJson(route('socialhq.ai-graphics.store'), [
        'prompt' => 'Our momo plate with the headline "Happy Dashain"',
        'format' => 'square',
        'use_brand_look' => true,
        'use_brand_logo' => true,
        'references' => [UploadedFile::fake()->image('a.jpg', 100, 100), UploadedFile::fake()->image('b.jpg', 100, 100)],
    ])->assertAccepted()->json('import_id');

    $graphic = AiGraphic::query()->where('import_id', $importId)->sole();
    expect($graphic->status)->toBe(AiGraphic::GENERATED)
        ->and($graphic->reference_count)->toBe(3)
        ->and($graphic->brand_logo)->toBeTrue()
        ->and($graphic->brand_look)->toContain('Navy #0b1f3a')
        ->and(Storage::disk('local')->allFiles('socialhq/ai-graphics/references'))->toBe([])
        ->and(Storage::disk(LogoStorage::DISK)->exists(BrandLook::of($this->workspace)->logo_path))->toBeTrue();

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->attachments->count() === 3
        && $prompt->contains("The first attached image is the brand's logo")
        && $prompt->contains('Brand colours: Navy #0b1f3a'));
    GraphicSafetyReviewer::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), "company's own logo"));

    // The logo takes one of the three places.
    $this->postJson(route('socialhq.ai-graphics.store'), [
        'prompt' => 'Another graphic',
        'format' => 'square',
        'use_brand_logo' => true,
        'references' => array_fill(0, 3, UploadedFile::fake()->image('c.jpg', 100, 100)),
    ])->assertUnprocessable()->assertJsonValidationErrors('references');
});
