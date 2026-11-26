<script setup lang="ts">
import { Head, Link, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconBrandFacebook,
    IconClock,
    IconForms,
    IconId,
    IconLink,
    IconPencil,
    IconPlugConnectedX,
    IconPlugX,
    IconRefresh,
    IconTargetArrow,
    IconWorld,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import DisconnectChannelDialog from '@/components/channels/DisconnectChannelDialog.vue';
import PostingScheduleAddSlot from '@/components/channels/PostingScheduleAddSlot.vue';
import PostingScheduleGenerateMenu from '@/components/channels/PostingScheduleGenerateMenu.vue';
import PostingScheduleGrid from '@/components/channels/PostingScheduleGrid.vue';
import ChannelGoalDialog from '@/components/socialhq/channels/ChannelGoalDialog.vue';
import ChannelInviteDialog from '@/components/socialhq/channels/ChannelInviteDialog.vue';
import ChannelNameDialog from '@/components/socialhq/channels/ChannelNameDialog.vue';
import ChannelSettingRow from '@/components/socialhq/channels/ChannelSettingRow.vue';
import ChannelSideList from '@/components/socialhq/channels/ChannelSideList.vue';
import ChannelStatTiles, {
    type ChannelStats,
} from '@/components/socialhq/channels/ChannelStatTiles.vue';
import ChannelTimesDialog from '@/components/socialhq/channels/ChannelTimesDialog.vue';
import ChannelTimezoneChange from '@/components/socialhq/channels/ChannelTimezoneChange.vue';
import { firstErrorOf } from '@/components/socialhq/requestErrors';
import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    cleared,
    emptySchedule,
    slotCount,
    withTime,
} from '@/lib/postingSchedule';
import { copyToClipboard } from '@/lib/utils';
import { orderedWeekdays } from '@/preferences';
import { publish } from '@/routes/app/channels';
import { copy, generate, update } from '@/routes/app/channels/posting-schedule';
import { channels as channelsRoute } from '@/routes/app/workspace';
import { store as storeConnectInvite } from '@/routes/socialhq/channels/connect-invites';
import { destroy as cancelConnectInvite } from '@/routes/socialhq/connect-invites';
import { Platform } from '@/types/platform';
import type {
    ChannelScheduleState,
    OtherChannel,
    PostingSchedule,
    ScheduleGenerateAction,
    TimezoneOption,
} from '@/types/posting-schedule';
import {
    isConnectionLost,
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/types/social-account';

const DEFAULT_GOAL = 3;
const RANGE_MIN_DAYS = 3;

/** A connect link of the workspace that still works (ConnectInvite::openForPage). */
interface OpenInvite {
    id: number;
    network: string;
    /** The channel it reconnects, or null for a new connection. */
    reconnect: string | null;
    created_by: string | null;
    created_at: string;
    expires_at: string;
}

const props = defineProps<{
    channel: ConnectedAccount;
    schedule: ChannelScheduleState;
    timezones: TimezoneOption[];
    otherChannels: OtherChannel[];
    stats: ChannelStats;
    week: { sent: number; goal: number | null };
    name: { custom: string | null; network: string };
    invite: { supported: boolean; reconnecting: boolean; open: OpenInvite[] };
}>();

const state = ref<ChannelScheduleState>({ ...props.schedule });
const http = useHttp<Record<string, any>, ChannelScheduleState>({});

const currentSchedule = computed<PostingSchedule>(
    () => state.value.posting_schedule ?? emptySchedule(),
);

let confirmed: ChannelScheduleState = { ...props.schedule };
let queue: Promise<void> = Promise.resolve();
let queued = 0;

const send = (
    request: () => Promise<ChannelScheduleState>,
    optimistic?: ChannelScheduleState,
): Promise<void> => {
    if (optimistic) {
        state.value = optimistic;
    }

    queued += 1;

    const run = async (): Promise<void> => {
        try {
            const response = await request();

            if (!response) {
                throw new Error('invalid');
            }

            confirmed = response;

            if (queued === 1) {
                state.value = response;
            }
        } catch {
            toast.error(trans('channels.settings_page.save_failed'));

            if (queued === 1) {
                state.value = confirmed;
            }
        } finally {
            queued -= 1;
        }
    };

    queue = queue.then(run);

    return queue;
};

const save = (next: ChannelScheduleState): Promise<void> =>
    send(
        () =>
            http
                .transform(() => ({
                    timezone: next.timezone,
                    posting_goal: next.posting_goal,
                    posting_schedule: next.posting_schedule,
                }))
                .put(update.url(props.channel.id)),
        next,
    );

const goal = computed(() => state.value.posting_goal ?? DEFAULT_GOAL);
const goalMet = computed(() => slotCount(currentSchedule.value) >= goal.value);

const saveGoal = (value: number): void => {
    void save({ ...state.value, posting_goal: value });
};

const changeTimezone = (timezone: string): void => {
    void save({ ...state.value, timezone });
};

const changeSchedule = (next: PostingSchedule): void => {
    void save({ ...state.value, posting_schedule: next });
};

const generateSchedule = (action: ScheduleGenerateAction): void => {
    if (action.kind === 'copy') {
        void send(() =>
            http
                .transform(() => ({ from: action.from }))
                .post(copy.url(props.channel.id)),
        );

        return;
    }

    void send(() =>
        http
            .transform(() => ({ mode: action.kind, goal: goal.value }))
            .post(generate.url(props.channel.id)),
    );
};

const addSlot = (days: number[], time: string): void => {
    changeSchedule(
        days.reduce(
            (schedule, day) => withTime(schedule, day, time),
            currentSchedule.value,
        ),
    );
};

const clearAll = (): void => {
    changeSchedule(cleared(currentSchedule.value));
};

// The days queued posts go out on, and whether they all share the same times.
const activeDays = computed(() =>
    currentSchedule.value.filter(
        (entry) => entry.enabled && entry.times.length > 0,
    ),
);
const sharedTimes = computed<string[] | null>(() => {
    const [first, ...rest] = activeDays.value;

    if (!first) {
        return [];
    }

    return rest.every((entry) => entry.times.join() === first.times.join())
        ? first.times
        : null;
});

const dayName = (day: number, format: string): string =>
    dayjs().locale(activeLocale.value.toLowerCase()).day(day).format(format);

const activeDaysInWeekOrder = computed(() => {
    const active = new Set(activeDays.value.map((entry) => entry.day));

    return orderedWeekdays().filter((day) => active.has(day));
});

interface DaysSummary {
    key: string | null;
    params: Record<string, string>;
    text: string;
}

const daysSummary = computed((): DaysSummary => {
    const days = activeDaysInWeekOrder.value;
    const order = orderedWeekdays();
    const positions = days.map((day) => order.indexOf(day));

    if (days.length === 0 || days.length === order.length) {
        return {
            key: 'socialhq.channels.times.every_day',
            params: {},
            text: '',
        };
    }

    if (
        days.length >= RANGE_MIN_DAYS &&
        positions[positions.length - 1] - positions[0] === days.length - 1
    ) {
        return {
            key: 'socialhq.channels.times.day_range',
            params: {
                from: dayName(days[0], 'dddd'),
                to: dayName(days[days.length - 1], 'dddd'),
            },
            text: '',
        };
    }

    return {
        key: null,
        params: {},
        text: days.map((day) => dayName(day, 'ddd')).join(', '),
    };
});

const timesList = computed(() =>
    (sharedTimes.value ?? [])
        .map((time) => date.formatClockTime(time))
        .join(', '),
);

const zoneLabel = computed(
    () =>
        props.timezones.find((option) => option.value === state.value.timezone)
            ?.label ?? state.value.timezone,
);

const goalOpen = ref(false);
const timesOpen = ref(false);
const nameOpen = ref(false);
const gridOpen = ref(false);
const gridSection = ref<HTMLElement | null>(null);

const openGoal = (): void => {
    goalOpen.value = true;
};

const openName = (): void => {
    nameOpen.value = true;
};

const openGrid = async (): Promise<void> => {
    gridOpen.value = true;
    await nextTick();
    gridSection.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

const closeGrid = (): void => {
    gridOpen.value = false;
};

const editTimes = (): void => {
    if (sharedTimes.value === null) {
        void openGrid();

        return;
    }

    timesOpen.value = true;
};

const saveTimes = (times: string[]): void => {
    const days = new Set(
        activeDays.value.length > 0
            ? activeDays.value.map((entry) => entry.day)
            : currentSchedule.value
                  .filter((entry) => entry.enabled)
                  .map((entry) => entry.day),
    );

    changeSchedule(
        currentSchedule.value.map((entry) =>
            days.has(entry.day) ? { ...entry, times: [...times] } : entry,
        ),
    );
};

const copyId = (): void => {
    void copyToClipboard(
        props.channel.id,
        trans('socialhq.channels.id_copied'),
    );
};

const page = usePage();
const platforms = computed<AvailablePlatform[]>(
    () => (page.props.connectablePlatforms as AvailablePlatform[]) ?? [],
);
const { openAt } = useConnectChannelDialog();
const { startConnect, instagramMethods } = useNetworkConnect(platforms);

const lost = computed(() => isConnectionLost(props.channel));
const networkLabel = computed(() => getPlatformLabel(props.channel.platform));
const canLinkFacebook = computed(
    () =>
        props.channel.platform === Platform.Instagram &&
        instagramMethods.value.includes(Platform.InstagramFacebook),
);

const reconnect = (): void => {
    startConnect(props.channel.platform, props.channel.id);
};

const linkFacebook = (): void => {
    openAt(Platform.InstagramFacebook, props.channel.id);
};

const disconnectDialog = ref<InstanceType<
    typeof DisconnectChannelDialog
> | null>(null);

const disconnect = (): void => {
    disconnectDialog.value?.open(props.channel);
};

// "Ask the account owner to connect": a link that works once, for an hour.
const inviteHttp = useHttp<Record<string, never>, { url: string }>({});
const inviteUrl = ref('');
const inviteOpen = ref(false);
const inviteLoading = ref(false);

const copyInvite = async (): Promise<void> => {
    inviteLoading.value = true;

    try {
        const response = await inviteHttp.post(
            storeConnectInvite.url(props.channel.id),
        );

        inviteUrl.value = response.url;
    } catch {
        toast.error(trans('socialhq.channels.invite.failed'));

        return;
    } finally {
        inviteLoading.value = false;
    }

    // The new link joins "Links still open".
    router.reload({ only: ['invite'] });

    try {
        await navigator.clipboard.writeText(inviteUrl.value);
        toast.success(trans('socialhq.channels.invite.copied'));
    } catch {
        // The browser refused to copy after the request: show the link instead.
        inviteOpen.value = true;
    }
};

// A link sent to the wrong person stops working once cancelled (SEC-17).
const cancellingInviteId = ref<number | null>(null);

const cancelInvite = (id: number): void => {
    router.delete(cancelConnectInvite.url(id), {
        preserveScroll: true,
        preserveState: true,
        only: ['invite', 'flash'],
        onStart: () => {
            cancellingInviteId.value = id;
        },
        onFinish: () => {
            cancellingInviteId.value = null;
        },
        onError: (errors) => {
            const message = firstErrorOf(
                errors as Record<string, string | undefined>,
            );

            if (message) {
                toast.error(message);
            }
        },
    });
};

const composeUrl = computed(() =>
    publish.url(props.channel.id, { query: { compose: 1 } }),
);
</script>

<template>
    <Head :title="channel.display_label" />

    <AppLayout full-width :title="$t('channels.title')">
        <div
            class="flex min-h-0 flex-1 max-lg:flex-col lg:overflow-hidden"
            data-testid="channel-settings-page"
        >
            <ChannelSideList :active-id="channel.id" class="max-lg:hidden" />

            <div
                class="min-w-0 flex-1 px-4 pt-4 pb-18 md:px-8 lg:overflow-y-auto"
                data-testid="channel-detail"
            >
                <div class="flex flex-wrap items-center gap-3 py-2">
                    <Button
                        as-child
                        variant="ghost"
                        size="icon"
                        class="shrink-0 lg:hidden"
                    >
                        <Link
                            :href="channelsRoute.url()"
                            :aria-label="$t('channels.settings_page.back')"
                            data-testid="channel-settings-back"
                        >
                            <IconArrowLeft
                                class="size-4 text-muted-foreground rtl:rotate-180"
                            />
                        </Link>
                    </Button>
                    <ChannelAvatar
                        :account-id="channel.id"
                        :platform="channel.platform"
                        :src="channel.avatar_url"
                        :verified="channel.verified_badge"
                        :name="channel.display_label"
                        :size="44"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <h1
                                class="truncate text-[17px] leading-6 font-emphasis text-foreground"
                                data-testid="channel-name"
                            >
                                {{ channel.display_label }}
                            </h1>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] leading-4 font-medium"
                                :class="
                                    lost
                                        ? 'bg-destructive/10 text-destructive-text'
                                        : 'bg-success-subtle text-success-text'
                                "
                                data-testid="channel-status"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="
                                        lost ? 'bg-destructive' : 'bg-success'
                                    "
                                    aria-hidden="true"
                                />
                                {{
                                    lost
                                        ? $t('socialhq.channels.status.lost')
                                        : $t(
                                              'socialhq.channels.status.connected',
                                          )
                                }}
                            </span>
                        </div>
                        <p class="truncate text-[13px] text-muted-foreground">
                            <a
                                v-if="channel.profile_url"
                                :href="channel.profile_url"
                                target="_blank"
                                rel="noopener"
                                class="hover:underline"
                                >@{{ channel.handle_label }}</a
                            >
                            <template v-else
                                >@{{ channel.handle_label }}</template
                            >
                            · {{ networkLabel }}
                        </p>
                    </div>
                    <Button as-child class="shrink-0">
                        <Link :href="composeUrl" data-testid="channel-new-post">
                            <IconPencil class="size-4" />
                            {{ $t('socialhq.channels.new_post') }}
                        </Link>
                    </Button>
                </div>

                <div
                    v-if="lost"
                    class="mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-[13px] text-foreground"
                    data-testid="channel-reconnect-banner"
                >
                    <IconPlugConnectedX
                        class="size-5 shrink-0 text-destructive"
                    />
                    <span class="min-w-0 flex-1">
                        <strong class="font-emphasis">{{
                            $t('socialhq.channels.reconnect.title')
                        }}</strong>
                        {{
                            stats.waiting > 0
                                ? $tChoice(
                                      'socialhq.channels.reconnect.body',
                                      stats.waiting,
                                      {
                                          count: String(stats.waiting),
                                          network: networkLabel,
                                      },
                                  )
                                : $t('socialhq.channels.reconnect.body_none', {
                                      network: networkLabel,
                                  })
                        }}
                    </span>
                    <Button
                        variant="destructive"
                        class="shrink-0"
                        data-testid="channel-reconnect"
                        @click="reconnect"
                    >
                        {{ $t('channels.reconnect') }}
                    </Button>
                </div>

                <ChannelStatTiles
                    class="mt-4"
                    :channel-id="channel.id"
                    :stats="stats"
                    :sent="week.sent"
                    :goal="state.posting_goal"
                />

                <h2
                    class="mt-6 mb-2 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    {{ $t('socialhq.channels.sections.channel') }}
                </h2>
                <div
                    class="rounded-xl border border-border"
                    data-testid="channel-rows"
                >
                    <ChannelSettingRow
                        :icon="IconWorld"
                        :title="$t('socialhq.channels.timezone.title')"
                        :description="
                            $t('socialhq.channels.timezone.description', {
                                zone: zoneLabel,
                            })
                        "
                        testid="channel-row-timezone"
                    >
                        <ChannelTimezoneChange
                            :timezone="state.timezone"
                            :timezones="timezones"
                            @change="changeTimezone"
                        />
                    </ChannelSettingRow>
                    <ChannelSettingRow
                        :icon="IconTargetArrow"
                        :title="$t('socialhq.channels.goal.title')"
                        :description="
                            state.posting_goal
                                ? $tChoice(
                                      'socialhq.channels.goal.value',
                                      state.posting_goal,
                                      { count: String(state.posting_goal) },
                                  )
                                : $t('socialhq.channels.goal.none')
                        "
                        testid="channel-row-goal"
                    >
                        <Button
                            variant="outline"
                            data-testid="channel-goal-edit"
                            @click="openGoal"
                        >
                            {{ $t('socialhq.channels.goal.action') }}
                        </Button>
                    </ChannelSettingRow>
                    <ChannelSettingRow
                        :icon="IconClock"
                        :title="$t('socialhq.channels.times.title')"
                        :description="
                            sharedTimes === null
                                ? $t('socialhq.channels.times.varied')
                                : sharedTimes.length === 0
                                  ? $t('socialhq.channels.times.none')
                                  : $t('socialhq.channels.times.value', {
                                        times: timesList,
                                        days: daysSummary.key
                                            ? $t(
                                                  daysSummary.key,
                                                  daysSummary.params,
                                              )
                                            : daysSummary.text,
                                    })
                        "
                        testid="channel-row-times"
                    >
                        <Button
                            variant="outline"
                            data-testid="channel-times-edit"
                            @click="editTimes"
                        >
                            {{ $t('socialhq.channels.times.action') }}
                        </Button>
                    </ChannelSettingRow>
                    <ChannelSettingRow
                        :icon="IconForms"
                        :title="$t('socialhq.channels.name.title')"
                        :description="$t('socialhq.channels.name.description')"
                        testid="channel-row-name"
                    >
                        <Button
                            variant="outline"
                            data-testid="channel-name-edit"
                            @click="openName"
                        >
                            {{ $t('socialhq.channels.name.action') }}
                        </Button>
                    </ChannelSettingRow>
                    <ChannelSettingRow
                        :icon="IconId"
                        :title="$t('socialhq.channels.id_title')"
                        :description="
                            $t('socialhq.channels.id_description', {
                                id: channel.id,
                            })
                        "
                        testid="channel-id"
                    >
                        <Button
                            variant="outline"
                            data-testid="channel-id-copy"
                            @click="copyId"
                        >
                            {{ $t('socialhq.channels.id_copy') }}
                        </Button>
                    </ChannelSettingRow>
                </div>

                <section
                    v-if="gridOpen"
                    ref="gridSection"
                    class="mt-4 flex flex-col gap-6 rounded-xl border border-border p-4"
                    data-testid="channel-times-grid"
                >
                    <div
                        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="min-w-0 md:max-w-[707px]">
                            <h2
                                class="text-base leading-5 font-emphasis text-foreground"
                            >
                                {{
                                    $t('socialhq.channels.times.per_day_title')
                                }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t(
                                        'channels.settings_page.slots_description',
                                    )
                                }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <PostingScheduleGenerateMenu
                                :goal-met="goalMet"
                                :other-channels="otherChannels"
                                @generate="generateSchedule"
                            />
                            <Button
                                variant="ghost"
                                data-testid="channel-times-grid-hide"
                                @click="closeGrid"
                            >
                                {{ $t('socialhq.channels.times.per_day_hide') }}
                            </Button>
                        </div>
                    </div>

                    <p
                        v-if="state.posting_schedule === null"
                        class="rounded-lg border border-dashed border-border-strong px-4 py-6 text-center text-sm text-muted-foreground"
                        data-testid="schedule-empty"
                    >
                        {{ $t('channels.settings_page.empty') }}
                    </p>

                    <PostingScheduleGrid
                        :schedule="currentSchedule"
                        @change="changeSchedule"
                    />

                    <PostingScheduleAddSlot
                        :schedule="currentSchedule"
                        :timezone="state.timezone"
                        @add="addSlot"
                        @clear="clearAll"
                    />
                </section>

                <h2
                    class="mt-6 mb-2 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    {{ $t('socialhq.channels.sections.connection') }}
                </h2>
                <div
                    class="rounded-xl border border-border"
                    data-testid="channel-connection"
                >
                    <ChannelSettingRow
                        :icon="IconRefresh"
                        :title="$t('socialhq.channels.refresh.title')"
                        :description="
                            $t('socialhq.channels.refresh.description')
                        "
                        testid="channel-row-refresh"
                    >
                        <Button
                            variant="outline"
                            data-testid="channel-refresh"
                            @click="reconnect"
                        >
                            {{ $t('socialhq.channels.refresh.action') }}
                        </Button>
                    </ChannelSettingRow>
                    <ChannelSettingRow
                        v-if="invite.supported"
                        :icon="IconLink"
                        :title="$t('socialhq.channels.invite.title')"
                        :description="
                            invite.reconnecting
                                ? $t(
                                      'socialhq.channels.invite.description_reconnect',
                                  )
                                : $t('socialhq.channels.invite.description')
                        "
                        testid="channel-row-invite"
                    >
                        <Button
                            variant="outline"
                            :loading="inviteLoading"
                            data-testid="channel-invite-copy"
                            @click="copyInvite"
                        >
                            {{ $t('socialhq.channels.invite.action') }}
                        </Button>
                    </ChannelSettingRow>
                    <div
                        v-if="invite.open.length > 0"
                        class="flex flex-col gap-2 border-b border-border px-4 py-3.5 last:border-b-0"
                        data-testid="channel-invites-open"
                    >
                        <div>
                            <p
                                class="text-sm leading-5 font-emphasis text-foreground"
                            >
                                {{ $t('socialhq.channels.invite.open_title') }}
                            </p>
                            <p class="text-[13px] text-muted-foreground">
                                {{
                                    $t(
                                        'socialhq.channels.invite.open_description',
                                    )
                                }}
                            </p>
                        </div>
                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="link in invite.open"
                                :key="link.id"
                                class="flex items-center gap-3 rounded-lg bg-muted px-3 py-2"
                                :data-testid="`channel-invite-open-${link.id}`"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-foreground">
                                        {{
                                            link.reconnect
                                                ? $t(
                                                      'socialhq.channels.invite.open_reconnect',
                                                      {
                                                          channel:
                                                              link.reconnect,
                                                      },
                                                  )
                                                : $t(
                                                      'socialhq.channels.invite.open_network',
                                                      { network: link.network },
                                                  )
                                        }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{
                                            link.created_by
                                                ? $t(
                                                      'socialhq.channels.invite.open_meta',
                                                      {
                                                          name: link.created_by,
                                                          time: date.formatTime(
                                                              link.expires_at,
                                                          ),
                                                      },
                                                  )
                                                : $t(
                                                      'socialhq.channels.invite.open_meta_unknown',
                                                      {
                                                          time: date.formatTime(
                                                              link.expires_at,
                                                          ),
                                                      },
                                                  )
                                        }}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :loading="cancellingInviteId === link.id"
                                    :data-testid="`channel-invite-cancel-${link.id}`"
                                    @click="cancelInvite(link.id)"
                                >
                                    {{ $t('socialhq.channels.invite.cancel') }}
                                </Button>
                            </li>
                        </ul>
                    </div>
                    <ChannelSettingRow
                        v-if="canLinkFacebook"
                        :icon="IconBrandFacebook"
                        :title="$t('channels.link_facebook_page')"
                        :description="
                            $t('socialhq.channels.link_facebook.description')
                        "
                        testid="channel-row-link-facebook"
                    >
                        <Button
                            variant="outline"
                            data-testid="channel-link-facebook"
                            @click="linkFacebook"
                        >
                            {{ $t('socialhq.channels.link_facebook.action') }}
                        </Button>
                    </ChannelSettingRow>
                    <ChannelSettingRow
                        :icon="IconPlugX"
                        :title="$t('socialhq.channels.disconnect.title')"
                        :description="
                            $t('socialhq.channels.disconnect.description')
                        "
                        danger
                        testid="channel-row-disconnect"
                    >
                        <Button
                            variant="outline"
                            class="border-destructive/30 text-destructive-text hover:bg-destructive/10 hover:text-destructive-text"
                            data-testid="channel-disconnect"
                            @click="disconnect"
                        >
                            {{ $t('socialhq.channels.disconnect.action') }}
                        </Button>
                    </ChannelSettingRow>
                </div>
            </div>
        </div>

        <ChannelGoalDialog
            v-model:open="goalOpen"
            :goal="goal"
            @save="saveGoal"
        />
        <ChannelTimesDialog
            v-model:open="timesOpen"
            :times="sharedTimes ?? []"
            :days-label="
                daysSummary.key
                    ? $t(daysSummary.key, daysSummary.params)
                    : daysSummary.text
            "
            @save="saveTimes"
            @per-day="openGrid"
        />
        <ChannelNameDialog
            v-model:open="nameOpen"
            :channel-id="channel.id"
            :custom-name="name.custom"
            :network-name="name.network"
            :network-label="networkLabel"
        />
        <ChannelInviteDialog v-model:open="inviteOpen" :url="inviteUrl" />
        <DisconnectChannelDialog
            ref="disconnectDialog"
            :keyword="$t('channels.disconnect_modal.keyword')"
            @refresh="reconnect"
        />
    </AppLayout>
</template>
