<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconAdjustmentsHorizontal,
    IconCalendarPlus,
    IconChecks,
    IconCircleCheck,
    IconList,
    IconPlug,
    IconPlus,
    IconSend,
    IconSparkles,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import PlanBrandSettingsDialog from '@/components/socialhq/plan/PlanBrandSettingsDialog.vue';
import PlanGenerateDialog from '@/components/socialhq/plan/PlanGenerateDialog.vue';
import PlanGenerationPanel from '@/components/socialhq/plan/PlanGenerationPanel.vue';
import PlanHeader from '@/components/socialhq/plan/PlanHeader.vue';
import PlanItemSheet from '@/components/socialhq/plan/PlanItemSheet.vue';
import PlanListView from '@/components/socialhq/plan/PlanListView.vue';
import PlanMonthGrid from '@/components/socialhq/plan/PlanMonthGrid.vue';
import PlanMonthSwitcher from '@/components/socialhq/plan/PlanMonthSwitcher.vue';
import {
    toastFirstError,
    toastRequestFailed,
} from '@/components/socialhq/plan/planRequests';
import PlanSummaryStrip from '@/components/socialhq/plan/PlanSummaryStrip.vue';
import PlanTabs from '@/components/socialhq/plan/PlanTabs.vue';
import PlanViewToggle, {
    type PlanView,
} from '@/components/socialhq/plan/PlanViewToggle.vue';
import type {
    PlanAbilities,
    PlanChannel,
    PlanData,
    PlanEvent,
    PlanItem,
    PlanLookups,
    PlanOwner,
} from '@/components/socialhq/plan/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAtLeastBreakpoint } from '@/composables/useBreakpoint';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import AppLayout from '@/layouts/AppLayout.vue';
import { channels as channelsSettings } from '@/routes/app/workspace';
import {
    approve as approvePlan,
    show as showPlan,
    submit as submitPlan,
} from '@/routes/socialhq/plan';
import {
    accept as acceptItem,
    drop as dropItem,
} from '@/routes/socialhq/plan/items';
import { accept as acceptSuggestions } from '@/routes/socialhq/plan/suggestions';

const props = defineProps<{
    month: string;
    today: string;
    plan: PlanData | null;
    items: PlanItem[];
    events: PlanEvent[];
    planChannels: PlanChannel[];
    owners: PlanOwner[];
    pillars: string[];
    cadence: Record<string, number | null>;
    creatives: string[];
    abilities: PlanAbilities;
    ai: { available: boolean; hourlyLimit: number };
}>();

const VIEW_STORAGE_KEY = 'socialhq.plan.view';

const readView = (): PlanView => {
    try {
        return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'list'
            ? 'list'
            : 'grid';
    } catch {
        return 'grid';
    }
};

const view = ref<PlanView>(readView());

watch(view, (next) => {
    try {
        window.localStorage.setItem(VIEW_STORAGE_KEY, next);
    } catch {
        // Storage blocked: the choice lasts until the page is left.
    }
});

/** The grid/list switch sits in the tab bar on md+, next to the month switcher below. */
const isDesktop = useAtLeastBreakpoint('md');

const monthLabel = computed(() =>
    dayjs(`${props.month}-01`)
        .locale(activeLocale.value.toLowerCase())
        .format('MMMM YYYY'),
);

const lookups = computed<PlanLookups>(() => ({
    channels: Object.fromEntries(
        props.planChannels.map((channel) => [channel.id, channel]),
    ),
    owners: Object.fromEntries(props.owners.map((owner) => [owner.id, owner])),
    events: Object.fromEntries(props.events.map((event) => [event.id, event])),
    pillars: props.pillars,
}));

const suggestions = computed(() =>
    props.items.filter((item) => item.status === 'suggested'),
);
const plannedCount = computed(
    () => props.items.filter((item) => item.status === 'planned').length,
);
const hasChannels = computed(() => props.planChannels.length > 0);
const isEmpty = computed(
    () => props.items.filter((item) => item.status !== 'dropped').length === 0,
);
const status = computed(() => props.plan?.status ?? 'draft');
const isGenerating = computed(
    () => props.plan?.generation.status === 'running',
);

const STATUS_BADGE: Record<string, 'secondary' | 'info' | 'success'> = {
    draft: 'secondary',
    in_review: 'info',
    approved: 'success',
};

const canSubmit = computed(
    () =>
        props.abilities.canWork &&
        status.value === 'draft' &&
        plannedCount.value > 0,
);
const canShowApprove = computed(
    () =>
        props.abilities.canApprove &&
        status.value !== 'approved' &&
        plannedCount.value > 0,
);
/** Why Approve is disabled, as a lang key resolved in the template; null when it isn't. */
const approveBlockedKey = computed<string | null>(() => {
    if (isGenerating.value) {
        return 'socialhq_plan.review.approve_generating';
    }

    return suggestions.value.length > 0
        ? 'socialhq_plan.review.approve_blocked'
        : null;
});
const approveBlocked = computed(() => approveBlockedKey.value !== null);

/* Polling while the AI drafts the month. */
const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['plan', 'items'] },
    { autoStart: false },
);

watch(
    isGenerating,
    (running) => {
        if (running) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

/* Writes. */
const busyItemId = ref<number | null>(null);
const reviewProcessing = ref(false);
const acceptAllProcessing = ref(false);

const writeOptions = {
    preserveScroll: true,
    preserveState: true,
    onError: toastFirstError,
    onHttpException: toastRequestFailed,
};

const itemWrite = (item: PlanItem, url: string): void => {
    router.post(
        url,
        {},
        {
            ...writeOptions,
            onStart: () => {
                busyItemId.value = item.id;
            },
            onFinish: () => {
                busyItemId.value = null;
            },
        },
    );
};

const acceptOne = (item: PlanItem): void =>
    itemWrite(item, acceptItem.url(item.id));

const dropOne = (item: PlanItem): void =>
    itemWrite(item, dropItem.url(item.id));

const acceptAll = (): void => {
    router.post(
        acceptSuggestions.url(props.month),
        {},
        {
            ...writeOptions,
            onStart: () => {
                acceptAllProcessing.value = true;
            },
            onFinish: () => {
                acceptAllProcessing.value = false;
            },
        },
    );
};

const reviewWrite = (url: string): void => {
    router.post(
        url,
        {},
        {
            ...writeOptions,
            onStart: () => {
                reviewProcessing.value = true;
            },
            onFinish: () => {
                reviewProcessing.value = false;
            },
        },
    );
};

const sendForReview = (): void => reviewWrite(submitPlan.url(props.month));

const approve = (): void => {
    if (!approveBlocked.value) {
        reviewWrite(approvePlan.url(props.month));
    }
};

const goToMonth = (month: string): void => {
    router.visit(showPlan.url(month), { preserveScroll: false });
};

/* Sheet, dialogs. */
const sheetOpen = ref(false);
const sheetItemId = ref<number | null>(null);
const sheetDay = ref<string | null>(null);
const sheetItem = computed(
    () => props.items.find((item) => item.id === sheetItemId.value) ?? null,
);

const openItem = (item: PlanItem): void => {
    sheetItemId.value = item.id;
    sheetDay.value = null;
    sheetOpen.value = true;
};

const addItemOn = (day: string | null): void => {
    sheetItemId.value = null;
    sheetDay.value = day;
    sheetOpen.value = true;
};

const addItem = (): void =>
    addItemOn(props.today.startsWith(props.month) ? props.today : null);

const generateOpen = ref(false);
const openGenerate = (): void => {
    generateOpen.value = true;
};

const brandSettingsOpen = ref(false);
const openBrandSettings = (): void => {
    brandSettingsOpen.value = true;
};
</script>

<template>
    <Head :title="`${$t('socialhq_plan.tabs.plan')} · ${monthLabel}`" />

    <AppLayout full-width>
        <template #header>
            <PlanHeader />
        </template>

        <template #header-actions>
            <div class="flex items-center gap-2">
                <Tooltip v-if="abilities.canManageBrand" :delay-duration="200">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="
                                $t('socialhq_plan.actions.brand_settings')
                            "
                            data-testid="plan-brand-settings"
                            @click="openBrandSettings"
                        >
                            <IconAdjustmentsHorizontal class="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{
                        $t('socialhq_plan.actions.brand_settings')
                    }}</TooltipContent>
                </Tooltip>
                <Button
                    v-if="abilities.canGenerate && hasChannels"
                    variant="outline"
                    class="max-sm:w-8 max-sm:px-0"
                    :disabled="isGenerating"
                    :aria-label="$t('socialhq_plan.actions.generate')"
                    data-testid="plan-generate"
                    @click="openGenerate"
                >
                    <IconSparkles class="size-4" />
                    <span class="max-sm:sr-only">{{
                        $t('socialhq_plan.actions.generate')
                    }}</span>
                </Button>
                <Button
                    v-if="abilities.canWork && hasChannels"
                    class="max-sm:w-8 max-sm:px-0"
                    :aria-label="$t('socialhq_plan.actions.add_item')"
                    data-testid="plan-add-item"
                    @click="addItem"
                >
                    <IconPlus class="size-4" />
                    <span class="max-sm:sr-only">{{
                        $t('socialhq_plan.actions.add_item')
                    }}</span>
                </Button>
            </div>
        </template>

        <div class="flex min-h-0 flex-1 flex-col">
            <PlanTabs active="plan">
                <template v-if="hasChannels && isDesktop" #filters>
                    <PlanViewToggle v-model="view" />
                </template>
            </PlanTabs>

            <div
                class="mx-4 mt-2 flex min-h-12 shrink-0 flex-wrap items-center gap-x-3 gap-y-2 py-1 md:mx-8"
                data-testid="plan-toolbar"
            >
                <PlanMonthSwitcher
                    :month="month"
                    :today="today"
                    @go="goToMonth"
                />
                <PlanViewToggle
                    v-if="hasChannels && !isDesktop"
                    v-model="view"
                />
                <Badge
                    :variant="STATUS_BADGE[status]"
                    class="h-6 px-2"
                    data-testid="plan-status"
                >
                    {{ $t(`socialhq_plan.status.${status}`) }}
                </Badge>

                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <p
                        v-if="!ai.available && abilities.canManageBrand"
                        class="text-xs text-muted-foreground"
                        data-testid="plan-ai-off"
                    >
                        {{ $t('socialhq_plan.ai_off') }}
                    </p>
                    <p
                        v-if="status === 'approved' && plan?.approver"
                        class="flex items-center gap-1 text-sm text-muted-foreground"
                    >
                        <IconCircleCheck
                            class="size-4 text-success"
                            aria-hidden="true"
                        />
                        {{
                            $t('socialhq_plan.review.approved_by', {
                                name: plan.approver,
                            })
                        }}
                    </p>
                    <p
                        v-else-if="
                            status === 'in_review' && !abilities.canApprove
                        "
                        class="text-sm text-muted-foreground"
                        data-testid="plan-waiting-approval"
                    >
                        {{ $t('socialhq_plan.review.waiting') }}
                    </p>
                    <Button
                        v-if="canSubmit"
                        variant="outline"
                        :loading="reviewProcessing"
                        data-testid="plan-submit"
                        @click="sendForReview"
                    >
                        <IconSend class="size-4" />
                        {{ $t('socialhq_plan.actions.submit') }}
                    </Button>
                    <Tooltip v-if="canShowApprove" :delay-duration="100">
                        <TooltipTrigger as-child>
                            <span
                                :aria-describedby="
                                    approveBlocked
                                        ? 'plan-approve-blocked'
                                        : undefined
                                "
                            >
                                <Button
                                    :disabled="approveBlocked"
                                    :loading="reviewProcessing"
                                    data-testid="plan-approve"
                                    @click="approve"
                                >
                                    <IconCircleCheck class="size-4" />
                                    {{ $t('socialhq_plan.actions.approve') }}
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent v-if="approveBlockedKey">
                            {{ $t(approveBlockedKey) }}
                        </TooltipContent>
                    </Tooltip>
                    <span
                        v-if="canShowApprove && approveBlockedKey"
                        id="plan-approve-blocked"
                        class="sr-only"
                        data-testid="plan-approve-blocked"
                        >{{ $t(approveBlockedKey) }}</span
                    >
                </div>
            </div>

            <EmptyState
                v-if="!hasChannels"
                :icon="IconPlug"
                :title="$t('socialhq_plan.empty.no_channels_title')"
                :description="
                    abilities.canManageBrand
                        ? $t('socialhq_plan.empty.no_channels_body')
                        : $t('socialhq_plan.empty.no_channels_member')
                "
                data-testid="plan-empty-channels"
            >
                <template v-if="abilities.canManageBrand" #action>
                    <Button as-child>
                        <Link :href="channelsSettings.url()">
                            {{ $t('socialhq_plan.empty.connect') }}
                        </Link>
                    </Button>
                </template>
            </EmptyState>

            <div
                v-else
                class="mx-4 mb-4 flex min-h-0 flex-1 flex-col gap-3 md:mx-8 md:mb-6"
            >
                <PlanGenerationPanel
                    :plan="plan"
                    :month-label="monthLabel"
                    :can-generate="abilities.canGenerate"
                    @retry="openGenerate"
                />

                <div
                    v-if="!abilities.canWork"
                    class="rounded-xl border border-border-strong bg-card px-4 py-3 text-sm text-muted-foreground"
                    data-testid="plan-read-only"
                >
                    {{ $t('socialhq_plan.read_only') }}
                </div>

                <div
                    v-if="isEmpty && !isGenerating"
                    class="flex flex-col gap-3 rounded-xl border border-dashed border-border-strong bg-card px-4 py-4 sm:flex-row sm:items-center"
                    data-testid="plan-empty"
                >
                    <IconCalendarPlus
                        class="size-6 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-foreground">
                            {{
                                $t('socialhq_plan.empty.no_plan_title', {
                                    month: monthLabel,
                                })
                            }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                abilities.canGenerate
                                    ? $t('socialhq_plan.empty.no_plan_body')
                                    : $t(
                                          'socialhq_plan.empty.no_plan_body_manual',
                                      )
                            }}
                        </p>
                    </div>
                    <div v-if="abilities.canWork" class="flex gap-2">
                        <Button
                            variant="outline"
                            data-testid="plan-empty-add"
                            @click="addItem"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('socialhq_plan.actions.add_item') }}
                        </Button>
                        <Button
                            v-if="abilities.canGenerate"
                            data-testid="plan-empty-generate"
                            @click="openGenerate"
                        >
                            <IconSparkles class="size-4" />
                            {{ $t('socialhq_plan.actions.generate') }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="suggestions.length"
                    class="flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-primary/50 bg-primary-subtle/40 px-4 py-2.5"
                    data-testid="plan-suggestions-bar"
                >
                    <IconSparkles
                        class="size-4 shrink-0 text-primary-text"
                        aria-hidden="true"
                    />
                    <p class="min-w-0 flex-1 text-sm text-foreground">
                        {{
                            $t('socialhq_plan.suggestions.open', {
                                count: String(suggestions.length),
                            })
                        }}
                    </p>
                    <Button
                        v-if="abilities.canWork"
                        size="sm"
                        variant="outline"
                        :loading="acceptAllProcessing"
                        data-testid="plan-accept-all"
                        @click="acceptAll"
                    >
                        <IconChecks class="size-4" />
                        {{ $t('socialhq_plan.suggestions.accept_all') }}
                    </Button>
                </div>

                <PlanSummaryStrip
                    v-if="!isEmpty"
                    :month="month"
                    :items="items"
                    :events="events"
                    :channels="planChannels"
                    :pillars="pillars"
                    :cadence="cadence"
                />

                <div
                    class="flex min-h-[28rem] flex-1 flex-col overflow-hidden rounded-xl border border-border-strong bg-card"
                    :aria-busy="isGenerating || undefined"
                >
                    <PlanMonthGrid
                        v-if="view === 'grid'"
                        :month="month"
                        :today="today"
                        :items="items"
                        :events="events"
                        :lookups="lookups"
                        :can-work="abilities.canWork"
                        :busy-item-id="busyItemId"
                        @open="openItem"
                        @accept="acceptOne"
                        @drop="dropOne"
                        @add="addItemOn"
                    />
                    <template v-else>
                        <PlanListView
                            v-if="items.length"
                            :items="items"
                            :lookups="lookups"
                            @open="openItem"
                        />
                        <EmptyState
                            v-else
                            :icon="IconList"
                            :title="
                                $t('socialhq_plan.empty.list_title', {
                                    month: monthLabel,
                                })
                            "
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>

    <PlanItemSheet
        v-model:open="sheetOpen"
        :item="sheetItem"
        :default-day="sheetDay"
        :month="month"
        :channels="planChannels"
        :events="events"
        :owners="owners"
        :pillars="pillars"
        :creatives="creatives"
        :can-work="abilities.canWork"
    />

    <PlanGenerateDialog
        v-if="abilities.canGenerate"
        v-model:open="generateOpen"
        :month="month"
        :month-label="monthLabel"
        :goals="plan?.goals ?? null"
        :has-suggestions="suggestions.length > 0"
        :hourly-limit="ai.hourlyLimit"
    />

    <PlanBrandSettingsDialog
        v-if="abilities.canManageBrand"
        v-model:open="brandSettingsOpen"
        :pillars="pillars"
        :cadence="cadence"
        :channels="planChannels"
    />
</template>
