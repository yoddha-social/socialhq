<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\AiGraphics\AiGraphic;
use App\SocialHq\AiGraphics\GraphicSafetyReviewer;
use App\Support\MediaImportStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

beforeEach(function () {
    Storage::fake('local');
    config([
        'socialhq.ai_graphics.enabled' => true,
        'socialhq.ai_graphics.provider' => 'gemini',
        'socialhq.ai_graphics.vision_check' => true,
        'socialhq.ai_graphics.vision_provider' => 'anthropic',
        'socialhq.ai_graphics.per_workspace_per_day' => 100,
        'ai.providers.gemini.key' => 'test-gemini-key',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    // A real PNG, so the stored media is a valid image.
    $this->png = base64_encode(UploadedFile::fake()->image('generated.png', 96, 96)->getContent());
});

function aiGraphicAllowed(int $times = 2): void
{
    GraphicSafetyReviewer::fake(array_fill(0, $times, ['allowed' => true, 'reason' => '']));
}

test('a prompt with a reference image becomes a graphic the composer can attach', function () {
    aiGraphicAllowed();
    Image::fake([$this->png]);

    $importId = $this->actingAs($this->owner)
        ->post(route('socialhq.ai-graphics.store'), [
            'prompt' => 'Our tea pack on a marigold background, headline "Happy Dashain"',
            'format' => 'story',
            'references' => [UploadedFile::fake()->image('product.jpg', 400, 400)],
        ], ['Accept' => 'application/json'])
        ->assertAccepted()
        ->json('import_id');

    $graphic = AiGraphic::query()->where('import_id', $importId)->sole();
    expect($graphic->status)->toBe(AiGraphic::GENERATED)
        ->and($graphic->reference_count)->toBe(1)
        ->and(Media::query()->find($graphic->media_id)?->workspace_id)->toBe($this->workspace->id)
        ->and(MediaImportStatus::find($importId, $this->owner->id, $this->workspace->id)['status'])->toBe(MediaImportStatus::DONE)
        ->and(Storage::disk('local')->allFiles('socialhq/ai-graphics'))->toBe([]);

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->contains('Happy Dashain')
        && $prompt->size === '9:16'
        && $prompt->attachments->count() === 1);
    GraphicSafetyReviewer::assertPrompted(fn ($prompt): bool => $prompt->contains('Happy Dashain'));

    $this->getJson(route('socialhq.ai-graphics.show', $importId))
        ->assertOk()
        ->assertJson(['status' => 'generated', 'reason' => null]);
});

test('the vision check blocks a request before anything is generated', function () {
    GraphicSafetyReviewer::fake([['allowed' => false, 'reason' => 'The reference photo shows a real person with a changed face.']]);
    Image::fake();

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'Swap the face in this photo', 'format' => 'square'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt' => 'The reference photo shows a real person with a changed face.']);

    Image::assertNothingGenerated();
    expect(AiGraphic::query()->sole()->status)->toBe(AiGraphic::BLOCKED);
});

test('the vision check blocks a generated graphic before it is stored', function () {
    GraphicSafetyReviewer::fake([
        ['allowed' => true, 'reason' => ''],
        ['allowed' => false, 'reason' => 'The graphic shows another company\'s logo.'],
    ]);
    Image::fake([$this->png]);

    $importId = $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'A poster for our shoes', 'format' => 'portrait'])
        ->assertAccepted()
        ->json('import_id');

    expect(AiGraphic::query()->sole())
        ->status->toBe(AiGraphic::BLOCKED)
        ->media_id->toBeNull()
        ->and(Media::query()->count())->toBe(0);

    $this->getJson(route('socialhq.ai-graphics.show', $importId))
        ->assertJson(['status' => 'blocked', 'reason' => 'The graphic shows another company\'s logo.']);
});

test('nothing is generated when the vision check cannot run', function () {
    GraphicSafetyReviewer::fake(fn () => throw new RuntimeException('provider down'));
    Image::fake();

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'A Tihar greeting', 'format' => 'square'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt' => __('socialhq.ai_graphics.errors.check_unavailable')]);

    Image::assertNothingGenerated();
});

test('reference images must be real bitmaps of a usable size, and at most three', function (Closure $references) {
    GraphicSafetyReviewer::fake();
    Image::fake();

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'A banner', 'format' => 'landscape', 'references' => $references()])
        ->assertUnprocessable();

    GraphicSafetyReviewer::assertNeverPrompted();
    Image::assertNothingGenerated();
})->with([
    'svg' => [fn () => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]],
    'text named png' => [fn () => [UploadedFile::fake()->createWithContent('photo.png', 'not an image')]],
    'too small' => [fn () => [UploadedFile::fake()->image('tiny.png', 10, 10)]],
    'four images' => [fn () => array_map(fn (int $i) => UploadedFile::fake()->image("ref{$i}.png", 100, 100), range(1, 4))],
]);

test('people who cannot create posts here cannot generate graphics', function () {
    $outsider = workspaceOutsider($this->workspace);
    Image::fake();

    $this->actingAs($outsider)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'A banner', 'format' => 'square'])
        ->assertForbidden();

    Image::assertNothingGenerated();
});

test('the feature is off without an image provider key, and the menu hides it', function () {
    config(['ai.providers.gemini.key' => null]);

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'A banner', 'format' => 'square'])
        ->assertForbidden();

    $this->get(route('app.members'))
        ->assertInertia(fn ($page) => $page->where('socialhq.aiGraphics.available', false));

    config(['ai.providers.gemini.key' => 'test-gemini-key']);

    $this->get(route('app.members'))
        ->assertInertia(fn ($page) => $page->where('socialhq.aiGraphics.available', true));
});

test('a workspace stops at its daily limit', function () {
    config(['socialhq.ai_graphics.per_workspace_per_day' => 1]);
    aiGraphicAllowed(4);
    Image::fake([$this->png, $this->png]);

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'First', 'format' => 'square'])
        ->assertAccepted();

    $this->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'Second', 'format' => 'square'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt' => __('socialhq.ai_graphics.errors.daily_limit')]);
});

test('a generation status is only visible to the person who asked', function () {
    aiGraphicAllowed();
    Image::fake([$this->png]);

    $importId = $this->actingAs($this->owner)
        ->postJson(route('socialhq.ai-graphics.store'), ['prompt' => 'A banner', 'format' => 'square'])
        ->json('import_id');

    $this->actingAs(workspaceMember($this->workspace, 'admin'))
        ->getJson(route('socialhq.ai-graphics.show', $importId))
        ->assertNotFound();
});
