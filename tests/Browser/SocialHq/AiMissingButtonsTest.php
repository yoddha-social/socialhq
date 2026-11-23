<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\AiGraphics\GraphicSafetyReviewer;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake((string) config('filesystems.default'));
});

/**
 * An admin of a workspace with a LinkedIn channel, signed in.
 */
function aiMissingAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    test()->actingAs($user = $user->fresh());

    return $user;
}

/**
 * What a button for AI the company lacks says to this person: the owner adds
 * a provider, anyone else is told the owner can.
 */
function aiMissingMessage(string $key, User $user): string
{
    return __($key.(CompanyAiAccess::canManage($user) ? '.owner' : '.member'));
}

/**
 * Clicks a button that is aria-disabled, as a person can (Playwright refuses to).
 */
function aiMissingClick(mixed $page, string $testId): void
{
    $page->script("(() => { document.querySelector('[data-testid=\"{$testId}\"]').click(); return true; })()");
}

/**
 * The item page of a plan item whose creative waits for its first upload.
 */
function aiMissingCreativePage(User $user): mixed
{
    $plan = ContentPlan::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id]);
    Brief::factory()->for($item, 'item')->create();

    $page = visit(route('socialhq.plan.items.show', $item->id))->resize(1440, 900);
    waitFor($page, '@item-creative-generate');

    return $page;
}

test('without an image provider, "Generate with AI" stays in the media menu and says what is missing', function () {
    config(['ai.providers.gemini.key' => null]);
    $user = aiMissingAdmin();
    $message = aiMissingMessage('socialhq.ai_graphics.missing.image', $user);

    $page = visit(route('app.posts.create'));
    waitFor($page, '@composer-base-media-source-menu');
    $page->click('@composer-base-media-source-menu');
    waitFor($page, '@media-source-socialhq-ai');

    expect($page->script('document.querySelector(\'[data-testid="media-source-socialhq-ai"]\').dataset.missing'))->toBe('true');

    $page->hover('@media-source-socialhq-ai');
    waitForText($page, '@ai-missing-tooltip', $message);

    $page->click('@media-source-socialhq-ai');
    waitForText($page, '[data-sonner-toast]', $message);

    $page->assertMissing('@socialhq-ai-graphic-dialog')
        ->assertNoJavaScriptErrors();
});

test('a graphic generated from the creative is uploaded to the current round with a note saying so', function () {
    GraphicSafetyReviewer::fake(array_fill(0, 2, ['allowed' => true, 'reason' => '']));
    Image::fake([base64_encode(UploadedFile::fake()->image('generated.png', 96, 96)->getContent())]);
    $user = aiMissingAdmin();

    $page = aiMissingCreativePage($user);
    $page->click('@item-creative-generate');
    waitFor($page, '@socialhq-ai-graphic-prompt');
    $page->fill('@socialhq-ai-graphic-prompt', 'A marigold banner for Tihar');
    $page->click('@socialhq-ai-graphic-submit');
    waitForGone($page, '@socialhq-ai-graphic-dialog');
    waitFor($page, '[data-testid^="item-creative-file-"]');
    waitForDatabase($page, fn (): bool => BriefFile::query()->exists());

    $file = BriefFile::query()->sole();

    expect($file->round)->toBe(1)
        ->and($file->mime_type)->toBe('image/png')
        ->and($file->original_name)->toStartWith('ai-graphic-')
        ->and($file->note)->toBe(__('socialhq_production.creative.ai_note', ['prompt' => 'A marigold banner for Tihar']))
        ->and($file->uploaded_by)->toBe($user->id);
    $page->assertNoJavaScriptErrors();
});

test('without AI that reads images, the creative\'s "Generate with AI" says graphics are checked first', function () {
    config(['socialhq.ai.routes.vision' => 'anthropic', 'ai.providers.anthropic.key' => null]);
    $user = aiMissingAdmin();
    $message = aiMissingMessage('socialhq.ai_graphics.missing.vision', $user);

    $page = aiMissingCreativePage($user);

    expect($page->script('document.querySelector(\'[data-testid="item-creative-generate"]\').getAttribute("aria-disabled")'))->toBe('true');

    aiMissingClick($page, 'item-creative-generate');
    waitForText($page, '[data-sonner-toast]', $message);

    $page->assertMissing('@socialhq-ai-graphic-dialog')
        ->assertNoJavaScriptErrors();
    expect(BriefFile::query()->exists())->toBeFalse();
});
