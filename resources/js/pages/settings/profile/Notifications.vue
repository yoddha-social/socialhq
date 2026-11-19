<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import NotificationPreferenceController from '@/actions/App/Http/Controllers/App/Settings/NotificationPreferenceController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    show as showNoticePreferences,
    update as updateNoticePreferences,
} from '@/routes/socialhq/notices/preferences';

type PreferenceField =
    | 'post_published'
    | 'post_failed'
    | 'account_disconnected'
    | 'post_note_added'
    | 'collaboration';

type Preferences = Record<PreferenceField, boolean>;

const props = defineProps<{
    preferences: Preferences;
}>();

const fields: PreferenceField[] = [
    'post_published',
    'post_failed',
    'account_disconnected',
    'post_note_added',
    'collaboration',
];

const values = ref<Preferences>(
    Object.fromEntries(
        fields.map((field) => [field, Boolean(props.preferences[field])]),
    ) as Preferences,
);
const savedField = ref<PreferenceField | null>(null);
const http = useHttp<Partial<Preferences>>({});
let queue: Promise<void> = Promise.resolve();

/**
 * Each switch saves on its own. Saves run one after another so a quick second
 * toggle never cancels the first; a failed save puts the switch back.
 */
const toggle = (field: PreferenceField, value: boolean): void => {
    const previous = values.value[field];
    values.value = { ...values.value, [field]: value };
    savedField.value = null;

    queue = queue.then(async () => {
        try {
            await http
                .transform(() => ({ [field]: value }))
                .patch(NotificationPreferenceController.update.url());
            savedField.value = field;
        } catch {
            values.value = { ...values.value, [field]: previous };
            toast.error(trans('settings.notifications.save_failed'));
        }
    });
};

/**
 * Social HQ (PATCHES.md row 229): the "In the app" column and the live notices
 * switch are kept by the notification bell's own preferences. They save the
 * same way: one switch at a time, put back when the save fails.
 */
interface NoticePreferences {
    in_app: Preferences;
    live: boolean;
}

const inApp = ref<Preferences | null>(null);
const live = ref(true);
const noticeHttp = useHttp<Record<string, boolean>, NoticePreferences>({});

const applyNoticePreferences = (preferences: NoticePreferences): void => {
    inApp.value = preferences.in_app;
    live.value = preferences.live;
};

onMounted(async () => {
    try {
        applyNoticePreferences(
            await noticeHttp.get(showNoticePreferences.url()),
        );
    } catch {
        toast.error(trans('settings.notifications.save_failed'));
    }
});

const saveNoticePreference = (
    change: Record<string, boolean>,
    undo: () => void,
): void => {
    queue = queue.then(async () => {
        try {
            applyNoticePreferences(
                await noticeHttp
                    .transform(() => change)
                    .patch(updateNoticePreferences.url()),
            );
        } catch {
            undo();
            toast.error(trans('settings.notifications.save_failed'));
        }
    });
};

const toggleInApp = (field: PreferenceField, value: boolean): void => {
    if (inApp.value === null) {
        return;
    }

    const previous = inApp.value;
    inApp.value = { ...previous, [field]: value };
    saveNoticePreference({ [field]: value }, () => {
        inApp.value = previous;
    });
};

const toggleLive = (value: boolean): void => {
    const previous = live.value;
    live.value = value;
    saveNoticePreference({ live: value }, () => {
        live.value = previous;
    });
};
</script>

<template>
    <Head :title="$t('settings.notifications.title')" />

    <SettingsLayout :title="$t('settings.notifications.title')">
        <SettingsSection
            :title="$t('settings.notifications.heading')"
            :description="$t('socialhq.notices.preferences.description')"
        >
            <div
                class="rounded-xl border border-border"
                data-testid="notifications-page"
            >
                <div
                    class="grid grid-cols-[minmax(0,1fr)_72px_72px] items-center border-b border-border px-4 py-3 text-xs font-medium text-subtle-foreground md:grid-cols-[minmax(0,1fr)_88px_88px] md:px-5"
                >
                    <span>{{ $t('socialhq.notices.preferences.event') }}</span>
                    <span class="text-center">{{
                        $t('socialhq.notices.preferences.in_app')
                    }}</span>
                    <span class="text-center">{{
                        $t('socialhq.notices.preferences.email')
                    }}</span>
                </div>
                <div
                    v-for="field in fields"
                    :key="field"
                    class="grid grid-cols-[minmax(0,1fr)_72px_72px] items-center border-b border-border px-4 py-3.5 last:border-b-0 md:grid-cols-[minmax(0,1fr)_88px_88px] md:px-5"
                >
                    <div class="flex min-w-0 flex-col gap-1 pe-3">
                        <Label
                            :for="field"
                            class="text-sm leading-tight font-emphasis"
                        >
                            {{ $t(`settings.notifications.${field}`) }}
                        </Label>
                        <p class="text-[13px] text-muted-foreground">
                            {{ $t(`settings.notifications.${field}_description`) }}
                        </p>
                    </div>
                    <span class="flex justify-center">
                        <Switch
                            :model-value="inApp?.[field] ?? true"
                            :disabled="inApp === null"
                            :aria-label="`${$t(`settings.notifications.${field}`)}: ${$t('socialhq.notices.preferences.in_app')}`"
                            :data-testid="`notifications-app-${field}`"
                            @update:model-value="
                                toggleInApp(field, Boolean($event))
                            "
                        />
                    </span>
                    <span class="flex justify-center">
                        <Switch
                            :id="field"
                            :model-value="values[field]"
                            :data-testid="`notifications-${field}`"
                            @update:model-value="toggle(field, Boolean($event))"
                        />
                    </span>
                </div>
            </div>

            <div
                class="mt-6 flex items-center justify-between gap-4 rounded-xl border border-border p-4"
            >
                <div class="min-w-0">
                    <Label
                        for="notifications-live"
                        class="text-sm leading-tight font-emphasis"
                    >
                        {{ $t('socialhq.notices.preferences.live_title') }}
                    </Label>
                    <p class="mt-1 text-[13px] text-muted-foreground">
                        {{ $t('socialhq.notices.preferences.live_description') }}
                    </p>
                </div>
                <Switch
                    id="notifications-live"
                    :model-value="live"
                    :disabled="inApp === null"
                    class="shrink-0"
                    data-testid="notifications-live"
                    @update:model-value="toggleLive(Boolean($event))"
                />
            </div>

            <span
                v-if="savedField"
                :data-testid="`notifications-saved-${savedField}`"
                class="sr-only"
            />
        </SettingsSection>
    </SettingsLayout>
</template>
