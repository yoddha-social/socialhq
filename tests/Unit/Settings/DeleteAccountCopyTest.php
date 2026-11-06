<?php

declare(strict_types=1);

use App\Enums\User\Locale;

/**
 * Content markers for destructive copy. Key parity across locales is covered by
 * LocalizationParityTest (MorphMap-style). These markers catch stale translations
 * that still have the key but omit the invited-members / conditional-delete warning.
 *
 * @var array<string, string>
 */
$accountDeleteInvitedMemberMarkers = [
    'en' => 'invited members',
    'uk' => 'запрошених учасників',
    'pt-BR' => 'membros convidados',
    'es' => 'miembros invitados',
    'fr' => 'membres invités',
    'de' => 'eingeladene Mitglieder',
    'it' => 'membri invitati',
    'nl' => 'uitgenodigde leden',
    'pl' => 'zaproszeni członkowie',
    'el' => 'προσκεκλημένα μέλη',
    'ja' => '招待されたメンバー',
    'ko' => '초대된 멤버',
    'zh' => '受邀成员',
    'ru' => 'приглашённые участники',
    'tr' => 'davet edilen üyeler',
    'ar' => 'الأعضاء المدعوون',
];

/**
 * @var array<string, string>
 */
$workspaceDeleteConditionalMemberMarkers = [
    'en' => 'without another Social HQ workspace',
    'uk' => 'без іншого workspace у Social HQ',
    'pt-BR' => 'sem outro workspace no Social HQ',
    'es' => 'sin otro workspace en Social HQ',
    'fr' => 'sans autre workspace Social HQ',
    'de' => 'ohne anderen Social HQ-Workspace',
    'it' => 'senza un altro workspace Social HQ',
    'nl' => 'zonder andere Social HQ-workspace',
    'pl' => 'bez innego workspace w Social HQ',
    'el' => 'χωρίς άλλο workspace στο Social HQ',
    'ja' => '別のSocial HQワークスペースがない',
    'ko' => '다른 Social HQ 워크스페이스가 없는',
    'zh' => '没有其他 Social HQ 工作区',
    'ru' => 'без другого workspace в Social HQ',
    'tr' => 'Başka bir Social HQ workspace',
    'ar' => 'مساحة عمل أخرى في Social HQ',
];

test('workspace delete members warning describes conditional permanent deletion', function () {
    $warning = trans_choice('settings.workspace.delete_members_warning', 1, ['count' => 1]);

    expect($warning)
        ->toContain('lose access')
        ->toContain('without another Social HQ workspace')
        ->toContain('permanently deleted')
        ->not->toContain('personal account');
});

test('account delete billing failure flash says nothing was deleted', function () {
    $flash = __('settings.flash.delete_failed_billing');

    expect($flash)
        ->toContain('Nothing was deleted')
        ->not->toContain('already removed');
});

test('account delete warning mentions invited members are permanently deleted', function () {
    expect(__('settings.delete_account.warning_message'))
        ->toContain('invited members')
        ->toContain('permanently deleted');

    expect(__('settings.delete_account.modal_description_password'))
        ->toContain('invited members')
        ->toContain('permanently deleted');
});

test('account delete modals mention invited members in every locale', function (string $locale, string $needle) {
    expect(__('settings.delete_account.modal_description_password', [], $locale))
        ->toContain($needle);

    expect(__('settings.delete_account.modal_description_email', ['email' => 'x@y.z'], $locale))
        ->toContain($needle);

    expect(__('settings.delete_account.warning_message', [], $locale))
        ->toContain($needle);
})->with(
    collect($accountDeleteInvitedMemberMarkers)
        ->map(fn (string $needle, string $locale): array => [$locale, $needle])
        ->all()
);

test('workspace delete members warning is conditional in every locale', function (string $locale, string $needle) {
    $warning = trans_choice(
        'settings.workspace.delete_members_warning',
        2,
        ['count' => 2],
        $locale,
    );

    expect($warning)->toContain($needle);
})->with(
    collect($workspaceDeleteConditionalMemberMarkers)
        ->map(fn (string $needle, string $locale): array => [$locale, $needle])
        ->all()
);

test('destructive copy locale markers cover every Locale', function () use (
    $accountDeleteInvitedMemberMarkers,
    $workspaceDeleteConditionalMemberMarkers,
) {
    expect(array_keys($accountDeleteInvitedMemberMarkers))
        ->toEqualCanonicalizing(Locale::values());

    expect(array_keys($workspaceDeleteConditionalMemberMarkers))
        ->toEqualCanonicalizing(Locale::values());
});
