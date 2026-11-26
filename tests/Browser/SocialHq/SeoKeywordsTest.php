<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;

/**
 * A brand whose profile says what it makes, where, and a word to avoid, with
 * its admin signed in. "cold brew kathmandu" is a good fit for it and "cheap
 * coffee" a conflict.
 *
 * @param  list<array{text: string, main: bool}>  $keywords
 * @return array{0: User, 1: Workspace}
 */
function seoKeywordsBrand(array $keywords = []): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    BrandProfile::query()->create([
        'workspace_id' => $workspace->id,
        'about' => 'Himal Brew makes cold brew in Kathmandu.',
        'audience' => 'Coffee lovers in Kathmandu',
        'words_to_avoid' => 'cheap',
        'keywords' => $keywords,
    ]);

    test()->actingAs($user = $user->fresh());

    return [$user, $workspace];
}

/**
 * Puts a file in the card's hidden file input, as choosing it does. The
 * plugin's attach() sends local paths, which Playwright refuses over its
 * websocket, so a DataTransfer stands in (as in ImageCropperTest).
 */
function seoKeywordsChooseFile(mixed $page, string $name, string $contents): void
{
    $base64 = base64_encode($contents);

    $page->script(<<<JS
        (() => {
            const input = document.querySelector('[data-testid="socialhq-keywords-file"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const data = new DataTransfer();
            data.items.add(new File([bytes], '{$name}', { type: 'text/csv' }));
            input.files = data.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        })();
    JS);
}

/**
 * Opens a row's ⋯ menu and picks one of its items.
 */
function seoKeywordsMenu(mixed $page, string $slug, string $item): void
{
    $page->click("@socialhq-keyword-menu-{$slug}");
    waitFor($page, "@socialhq-keyword-{$item}-{$slug}");
    $page->click("@socialhq-keyword-{$item}-{$slug}");
}

test('an admin adds a pasted list in the field the link focuses, moves one to supporting and removes another', function () {
    [, $workspace] = seoKeywordsBrand();

    $page = visit(route('socialhq.brand.index', ['tab' => 'strategy', 'focus' => 'keywords']))->resize(1440, 900);
    waitForState($page, '@socialhq-keywords-input', 'element === document.activeElement');

    $page->fill('@socialhq-keywords-input', 'cold brew kathmandu, cheap coffee')
        ->click('@socialhq-keywords-add');
    waitForDatabase($page, fn (): bool => BrandKeywords::forWorkspace($workspace) === [
        ['text' => 'cold brew kathmandu', 'main' => true],
        ['text' => 'cheap coffee', 'main' => true],
    ]);
    // The check comes back with the save: each row gets its verdict.
    waitFor($page, '@socialhq-keyword-cold-brew-kathmandu @socialhq-keyword-verdict-good');
    waitFor($page, '@socialhq-keyword-cheap-coffee @socialhq-keyword-verdict-conflict');

    seoKeywordsMenu($page, 'cheap-coffee', 'supporting');
    waitFor($page, '@socialhq-keywords-group-supporting @socialhq-keyword-cheap-coffee');
    waitForDatabase($page, fn (): bool => BrandKeywords::forWorkspace($workspace)[1] === ['text' => 'cheap coffee', 'main' => false]);

    seoKeywordsMenu($page, 'cold-brew-kathmandu', 'remove');
    waitForGone($page, '@socialhq-keyword-cold-brew-kathmandu');
    waitForDatabase($page, fn (): bool => BrandKeywords::forWorkspace($workspace) === [
        ['text' => 'cheap coffee', 'main' => false],
    ]);
    // The removed row had the focus: it moves to the row after it, not to the page.
    waitForState($page, '@socialhq-keyword-toggle-cheap-coffee', 'element === document.activeElement');

    $page->assertNoJavaScriptErrors();
});

test('keywords asked for as main while main is full go to supporting, said in one toast', function () {
    [, $workspace] = seoKeywordsBrand(array_map(
        fn (string $text): array => ['text' => $text, 'main' => true],
        ['cold brew kathmandu', 'cafe in kathmandu', 'kathmandu coffee shop', 'coffee kathmandu', 'brew kathmandu'],
    ));

    $page = visit(route('socialhq.brand.index', ['tab' => 'strategy', 'focus' => 'keywords']))->resize(1440, 900);
    waitFor($page, '@socialhq-keywords-input');
    $page->select('@socialhq-keywords-group', 'main')
        ->fill('@socialhq-keywords-input', 'iced coffee kathmandu, cold brew bottles')
        ->click('@socialhq-keywords-add');

    $message = __('socialhq.keywords.toasts.added_many_supporting', ['count' => 2, 'max' => 5, 'moved' => 2]);
    waitForText($page, '[data-sonner-toast]', $message);
    waitForDatabase($page, fn (): bool => count(BrandKeywords::forWorkspace($workspace)) === 7);

    expect(array_slice(BrandKeywords::forWorkspace($workspace), 5))->toBe([
        ['text' => 'iced coffee kathmandu', 'main' => false],
        ['text' => 'cold brew bottles', 'main' => false],
    ])
        ->and($page->script("document.querySelectorAll('[data-sonner-toast]').length"))->toBe(1);
    $page->assertNoJavaScriptErrors();
});

test('a file\'s keywords are checked before anything is saved, and saved only when the import is confirmed', function () {
    [, $workspace] = seoKeywordsBrand();

    $page = visit(route('socialhq.brand.index', ['tab' => 'strategy']))->resize(1440, 900);
    waitFor($page, '@socialhq-keywords-drop');

    seoKeywordsChooseFile($page, 'keywords.csv', "keyword,group\ncold brew kathmandu,main\ncheap coffee,supporting\nCold Brew Kathmandu,supporting\n\"latte art, kathmandu\",supporting\n");
    waitFor($page, '@socialhq-keywords-import-row-0 @socialhq-keyword-verdict-good');
    waitFor($page, '@socialhq-keywords-import-row-1 @socialhq-keyword-verdict-conflict');
    // The same keyword twice in the file, and a quoted cell whose comma goes.
    $page->assertSeeIn('@socialhq-keywords-import-row-2', __('socialhq.keywords.import.notes.repeated'))
        ->assertSeeIn('@socialhq-keywords-import-row-3', 'latte art kathmandu');

    expect(BrandKeywords::forWorkspace($workspace))->toBe([]);

    $page->click('@socialhq-keywords-import-confirm');
    waitForGone($page, '@socialhq-keywords-import');
    waitForDatabase($page, fn (): bool => BrandKeywords::forWorkspace($workspace) === [
        ['text' => 'cold brew kathmandu', 'main' => true],
        ['text' => 'cheap coffee', 'main' => false],
        ['text' => 'latte art kathmandu', 'main' => false],
    ]);
    waitFor($page, '@socialhq-keywords-group-supporting @socialhq-keyword-cheap-coffee');
    // The preview had the focus: it goes back to the field.
    waitForState($page, '@socialhq-keywords-input', 'element === document.activeElement');

    $page->assertNoJavaScriptErrors();
});

test('a member sees the keywords and their verdicts, without the field or the row menus, and advice that says who can act', function () {
    [, $workspace] = seoKeywordsBrand([['text' => 'cold brew kathmandu', 'main' => true], ['text' => 'cheap coffee', 'main' => false]]);
    test()->actingAs(workspaceMember($workspace));

    $page = visit(route('socialhq.brand.index', ['tab' => 'strategy', 'focus' => 'keywords']))->resize(1440, 900);
    waitFor($page, '@socialhq-keyword-cold-brew-kathmandu @socialhq-keyword-verdict-good');
    waitFor($page, '@socialhq-keyword-cheap-coffee @socialhq-keyword-verdict-conflict');

    $page->assertMissing('@socialhq-keywords-form')
        ->assertMissing('@socialhq-keyword-menu-cold-brew-kathmandu')
        ->assertSeeIn('@socialhq-keyword-cheap-coffee', __('socialhq.keywords.check.member_fixes.conflict'))
        ->assertSeeIn('@socialhq-keywords-check', trans_choice('socialhq.keywords.check.all_to_ai_member', 2, ['count' => 2]))
        ->assertNoJavaScriptErrors();
});

test('a list pasted over selected text takes its place, and the group goes back to Main when a main slot frees', function () {
    seoKeywordsBrand(array_map(
        fn (string $text): array => ['text' => $text, 'main' => true],
        ['cold brew kathmandu', 'cafe in kathmandu', 'kathmandu coffee shop', 'coffee kathmandu', 'brew kathmandu'],
    ));

    $page = visit(route('socialhq.brand.index', ['tab' => 'strategy', 'focus' => 'keywords']))->resize(1440, 900);
    waitForState($page, '@socialhq-keywords-group', "element.value === 'supporting'");

    seoKeywordsMenu($page, 'brew-kathmandu', 'remove');
    waitForState($page, '@socialhq-keywords-group', "element.value === 'main'");

    $page->fill('@socialhq-keywords-input', 'text to replace');
    $page->script(<<<'JS'
        (() => {
            const input = document.querySelector('[data-testid="socialhq-keywords-input"]');
            const data = new DataTransfer();
            input.focus();
            input.select();
            data.setData('text/plain', 'iced latte\ncold brew bottles');
            input.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
            return true;
        })();
    JS);
    waitForState($page, '@socialhq-keywords-input', "element.value === 'iced latte, cold brew bottles'");

    $page->assertNoJavaScriptErrors();
});

test('"Add an entry" on a keyword nothing backs up opens the entry dialog with the keyword as its title', function () {
    seoKeywordsBrand([['text' => 'organic tea', 'main' => true]]);

    $page = visit(route('socialhq.brand.index', ['tab' => 'strategy', 'focus' => 'keywords']))->resize(1440, 900);
    waitFor($page, '@socialhq-keyword-organic-tea @socialhq-keyword-verdict-weak');
    $page->click('@socialhq-keyword-toggle-organic-tea');
    waitFor($page, '@socialhq-keyword-add-entry-organic-tea');
    $page->click('@socialhq-keyword-add-entry-organic-tea');

    waitForState($page, '@socialhq-knowledge-title', "element.value === 'Organic tea'");
    $page->assertNoJavaScriptErrors();
});

test('after the composer assistant writes for a brand without keywords, an admin gets a note linking to them, and × hides it', function () {
    config()->set('trypost.self_hosted', true);
    [, $workspace] = seoKeywordsBrand();
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    PostWritingAssistant::fake(['Cold brew, poured slow.']);

    $page = visit(route('app.posts.create'))->resize(1440, 900);
    // A click that lands while the composer still animates open is swallowed.
    waitForState($page, '@post-composer-dialog', "element.getAttribute('data-state') === 'open' && element.getAnimations().every((animation) => animation.playState !== 'running')");
    waitFor($page, '@composer-add-account');
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$channel->id}");
    $page->click("@composer-account-option-{$channel->id}");
    waitFor($page, '@composer-ai-assistant');
    $page->click('@composer-ai-assistant');
    waitFor($page, '@writing-assistant-panel');

    $page->click('@writing-assistant-mode-generate')
        ->fill('@writing-assistant-prompt', 'our new cold brew launch')
        ->click('@writing-assistant-generate');
    waitForText($page, '@writing-assistant-suggestion', 'Cold brew, poured slow.');
    waitFor($page, '@socialhq-keywords-missing-note');

    $link = $page->script(<<<'JS'
        (() => {
            const link = document.querySelector('[data-testid="socialhq-keywords-missing-note-link"]');
            return { href: link.getAttribute('href'), target: link.getAttribute('target') };
        })();
    JS);

    expect($link['href'])->toContain('focus=keywords')
        ->and($link['target'])->toBe('_blank');

    $page->click('@socialhq-keywords-missing-note-hide');
    waitForGone($page, '@socialhq-keywords-missing-note');

    $hiddenForDays = $page->script("(Number(localStorage.getItem('socialhq.keywordsNote.hiddenUntil.{$workspace->id}')) - Date.now()) / 86400000");

    expect($hiddenForDays)->toBeGreaterThan(6.9)->toBeLessThanOrEqual(7.0);
    $page->assertNoJavaScriptErrors();
});

test('after AI writes an item\'s copy for a brand without keywords, a member gets the note without the link', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.write' => 'anthropic',
    ]);
    [, $workspace] = seoKeywordsBrand();
    $item = PlanItem::factory()->create(['plan_id' => ContentPlan::factory()->create(['workspace_id' => $workspace->id])->id]);
    CopyWriter::fake([['caption' => 'Cold brew, poured slow.', 'hashtags' => '#coldbrew', 'script' => '', 'notes' => '']]);
    test()->actingAs(workspaceMember($workspace));

    $page = visit(route('socialhq.plan.items.show', $item->id))->resize(1440, 900);
    waitFor($page, '@item-copy-ai');
    $page->click('@item-copy-ai');
    waitFor($page, '@socialhq-keywords-missing-note');

    $page->assertSeeIn('@socialhq-keywords-missing-note', __('socialhq.keywords.note.member'))
        ->assertMissing('@socialhq-keywords-missing-note-link')
        ->assertNoJavaScriptErrors();
});

test('after AI writes an item\'s copy for a brand with keywords, the line lists the ones it says and the main one in the first line', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.write' => 'anthropic',
    ]);
    [, $workspace] = seoKeywordsBrand([['text' => 'cold brew kathmandu', 'main' => true], ['text' => 'organic tea', 'main' => false]]);
    $item = PlanItem::factory()->create(['plan_id' => ContentPlan::factory()->create(['workspace_id' => $workspace->id])->id]);
    CopyWriter::fake([['caption' => "Cold brew in Kathmandu, poured slow.\n\nBack for the summer.", 'hashtags' => '#coldbrew', 'script' => '', 'notes' => '']]);

    $page = visit(route('socialhq.plan.items.show', $item->id))->resize(1440, 900);
    waitFor($page, '@item-copy-ai');
    $page->click('@item-copy-ai');
    waitFor($page, '@socialhq-keywords-used');

    $page->assertSeeIn('@socialhq-keywords-used-keyword', 'cold brew kathmandu')
        ->assertVisible('@socialhq-keywords-used-first-line')
        ->assertMissing('@socialhq-keywords-missing-note')
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelectorAll('[data-testid=\"socialhq-keywords-used-keyword\"]').length"))->toBe(1);
});
