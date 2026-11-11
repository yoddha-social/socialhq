<script setup lang="ts">
import { Deferred, Head, router, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArrowDown,
    IconArrowUp,
    IconDots,
    IconPlus,
    IconSparkles,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import ProviderKeyDialog from '@/components/socialhq/ai/ProviderKeyDialog.vue';
import ProviderLogo from '@/components/socialhq/ai/ProviderLogo.vue';
import type {
    CatalogueProvider,
    CompanyProvider,
    CompanySpend,
    TaskRoute,
} from '@/components/socialhq/ai/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { update as updateBudget } from '@/routes/socialhq/ai-settings/budget';
import { destroy, move, test } from '@/routes/socialhq/ai-settings/providers';

const props = defineProps<{
    blocker: string | null;
    usesPlatformAi: boolean;
    catalogue: CatalogueProvider[];
    providers: CompanyProvider[];
    tasks: TaskRoute[];
    budget: { monthlyUsd: number | null; duration: string };
    spend?: CompanySpend | null;
}>();

const logoOf = (id: string): string =>
    props.catalogue.find((provider) => provider.id === id)?.logo ?? id;

const statusVariant = (
    status: CompanyProvider['status'],
): 'success' | 'destructive-subtle' | 'secondary' =>
    status === 'ok'
        ? 'success'
        : status === 'failed'
          ? 'destructive-subtle'
          : 'secondary';

const added = computed(() => props.providers.map((item) => item.provider));
const canAdd = computed(
    () =>
        props.blocker === null &&
        props.catalogue.some((provider) => !added.value.includes(provider.id)),
);

const formatUsd = (value: number): string =>
    new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
    }).format(value);

// Add / replace
const isKeyDialogOpen = ref(false);
const replacing = ref<CompanyProvider | null>(null);

const openAdd = (): void => {
    replacing.value = null;
    isKeyDialogOpen.value = true;
};

const openReplace = (item: CompanyProvider): void => {
    replacing.value = item;
    isKeyDialogOpen.value = true;
};

// Test and order
const busyId = ref<number | null>(null);

const runTest = (item: CompanyProvider): void => {
    router.post(
        test.url(item.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busyId.value = item.id;
            },
            onFinish: () => {
                busyId.value = null;
            },
        },
    );
};

const moveProvider = (
    item: CompanyProvider,
    direction: 'up' | 'down',
): void => {
    router.post(move.url(item.id), { direction }, { preserveScroll: true });
};

const moveUp = (item: CompanyProvider): void => moveProvider(item, 'up');
const moveDown = (item: CompanyProvider): void => moveProvider(item, 'down');

// Remove
const removing = ref<CompanyProvider | null>(null);
const isRemoveOpen = ref(false);
const isRemoving = ref(false);

const openRemove = (item: CompanyProvider): void => {
    removing.value = item;
    isRemoveOpen.value = true;
};

const closeRemove = (): void => {
    isRemoveOpen.value = false;
};

const confirmRemove = (): void => {
    if (removing.value === null) {
        return;
    }

    router.delete(destroy.url(removing.value.id), {
        preserveScroll: true,
        onStart: () => {
            isRemoving.value = true;
        },
        onFinish: () => {
            isRemoving.value = false;
        },
        onSuccess: closeRemove,
    });
};

// Budget
const budgetForm = useForm({
    monthly_budget_usd:
        props.budget.monthlyUsd === null ? '' : String(props.budget.monthlyUsd),
});

const saveBudget = (): void => {
    budgetForm
        .transform((data) => ({
            monthly_budget_usd:
                data.monthly_budget_usd.trim() === ''
                    ? null
                    : data.monthly_budget_usd.trim(),
        }))
        .put(updateBudget.url(), { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('socialhq_ai.page.title')" />

    <SettingsLayout
        :title="$t('socialhq_ai.page.title')"
        :description="$t('socialhq_ai.page.description')"
    >
        <div class="flex flex-col gap-10" data-testid="socialhq-ai-settings">
            <div
                v-if="blocker"
                class="flex items-start gap-3 rounded-xl border border-border bg-muted p-4"
                data-testid="socialhq-ai-blocker"
            >
                <IconAlertTriangle
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                />
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium">
                        {{ $t('socialhq_ai.blocker.title') }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ $t(`socialhq_ai.blocker.${blocker}`) }}
                    </p>
                </div>
            </div>

            <section
                class="flex flex-col gap-3"
                data-testid="socialhq-ai-status"
            >
                <h2 class="text-base font-medium">
                    {{ $t('socialhq_ai.status.title') }}
                </h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li
                        v-for="route in tasks"
                        :key="route.task"
                        class="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2 text-sm"
                        :data-testid="`socialhq-ai-task-${route.task}`"
                    >
                        <span>{{
                            $t(`socialhq_ai.status.tasks.${route.task}`)
                        }}</span>
                        <span
                            class="shrink-0 text-right text-muted-foreground"
                            :class="{ 'text-foreground': route.provider }"
                        >
                            {{
                                route.provider === 'platform'
                                    ? $t('socialhq_ai.status.platform')
                                    : route.provider
                                      ? $t(
                                            `socialhq_ai.providers.${route.provider}.name`,
                                        )
                                      : $t('socialhq_ai.status.none')
                            }}
                        </span>
                    </li>
                </ul>
                <p
                    v-if="usesPlatformAi && providers.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('socialhq_ai.status.uses_platform') }}
                </p>
            </section>

            <EmptyState
                v-if="providers.length === 0"
                :icon="IconSparkles"
                :title="$t('socialhq_ai.empty.title')"
                :description="$t('socialhq_ai.empty.body')"
                data-testid="socialhq-ai-empty"
            >
                <template v-if="canAdd" #action>
                    <Button data-testid="socialhq-ai-add" @click="openAdd">
                        <IconPlus class="size-4" />
                        {{ $t('socialhq_ai.list.add') }}
                    </Button>
                </template>
            </EmptyState>

            <section
                v-else
                class="flex flex-col gap-3"
                data-testid="socialhq-ai-providers"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-col gap-1">
                        <h2 class="text-base font-medium">
                            {{ $t('socialhq_ai.list.title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('socialhq_ai.list.order_hint') }}
                        </p>
                    </div>
                    <Button
                        v-if="canAdd"
                        variant="outline"
                        data-testid="socialhq-ai-add"
                        @click="openAdd"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('socialhq_ai.list.add') }}
                    </Button>
                </div>

                <ul class="flex flex-col gap-2">
                    <SettingsListRow
                        v-for="(item, index) in providers"
                        :key="item.id"
                        :data-testid="`socialhq-ai-provider-${item.provider}`"
                    >
                        <template #media>
                            <ProviderLogo :logo="logoOf(item.provider)" />
                        </template>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">
                                {{
                                    $t(
                                        `socialhq_ai.providers.${item.provider}.name`,
                                    )
                                }}
                            </span>
                            <Badge
                                :variant="statusVariant(item.status)"
                                :data-testid="`socialhq-ai-provider-status-${item.provider}`"
                            >
                                {{
                                    $t(`socialhq_ai.list.status.${item.status}`)
                                }}
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('socialhq_ai.list.key_ending', {
                                    last4: item.last4,
                                })
                            }}
                            ·
                            {{
                                item.lastTestedAt
                                    ? $t('socialhq_ai.list.tested', {
                                          time: date.diffForHumans(
                                              item.lastTestedAt,
                                          ),
                                      })
                                    : $t('socialhq_ai.list.never_tested')
                            }}
                        </p>
                        <p
                            v-if="item.retired"
                            class="text-sm text-destructive-text"
                        >
                            {{ $t('socialhq_ai.list.retired') }}
                        </p>
                        <p
                            v-else-if="
                                item.status === 'failed' && item.lastError
                            "
                            class="text-sm text-destructive-text"
                        >
                            {{ $t(`socialhq_ai.errors.${item.lastError}`) }}
                        </p>

                        <template #actions>
                            <Button
                                variant="outline"
                                size="sm"
                                :loading="busyId === item.id"
                                :disabled="item.retired || blocker !== null"
                                :data-testid="`socialhq-ai-test-${item.provider}`"
                                @click="runTest(item)"
                            >
                                {{ $t('socialhq_ai.list.actions.test') }}
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        :aria-label="
                                            $t('socialhq_ai.list.actions.menu')
                                        "
                                        :data-testid="`socialhq-ai-menu-${item.provider}`"
                                    >
                                        <IconDots class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="index > 0"
                                        :data-testid="`socialhq-ai-up-${item.provider}`"
                                        @select="moveUp(item)"
                                    >
                                        <IconArrowUp class="size-4" />
                                        {{
                                            $t(
                                                'socialhq_ai.list.actions.move_up',
                                            )
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="index < providers.length - 1"
                                        :data-testid="`socialhq-ai-down-${item.provider}`"
                                        @select="moveDown(item)"
                                    >
                                        <IconArrowDown class="size-4" />
                                        {{
                                            $t(
                                                'socialhq_ai.list.actions.move_down',
                                            )
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!item.retired && blocker === null"
                                        :data-testid="`socialhq-ai-replace-${item.provider}`"
                                        @select="openReplace(item)"
                                    >
                                        {{
                                            $t(
                                                'socialhq_ai.list.actions.replace',
                                            )
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        :data-testid="`socialhq-ai-remove-${item.provider}`"
                                        @select="openRemove(item)"
                                    >
                                        {{
                                            $t(
                                                'socialhq_ai.list.actions.remove',
                                            )
                                        }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </template>
                    </SettingsListRow>
                </ul>
            </section>

            <section
                class="flex flex-col gap-3"
                data-testid="socialhq-ai-budget"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="text-base font-medium">
                        {{ $t('socialhq_ai.budget.title') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('socialhq_ai.budget.description') }}
                    </p>
                </div>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="saveBudget"
                >
                    <div class="flex flex-col gap-2">
                        <Label for="socialhq-ai-budget">
                            {{ $t('socialhq_ai.budget.label') }}
                        </Label>
                        <Input
                            id="socialhq-ai-budget"
                            v-model="budgetForm.monthly_budget_usd"
                            inputmode="decimal"
                            class="w-40"
                            :aria-invalid="
                                Boolean(budgetForm.errors.monthly_budget_usd)
                            "
                            data-testid="socialhq-ai-budget-input"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        :loading="budgetForm.processing"
                        :disabled="blocker !== null"
                        data-testid="socialhq-ai-budget-save"
                    >
                        {{ $t('socialhq_ai.budget.save') }}
                    </Button>
                </form>
                <p
                    v-if="budgetForm.errors.monthly_budget_usd"
                    class="text-sm text-destructive"
                    data-testid="socialhq-ai-budget-error"
                >
                    {{ budgetForm.errors.monthly_budget_usd }}
                </p>
                <Deferred data="spend">
                    <template #fallback>
                        <Skeleton class="h-5 w-56" />
                    </template>
                    <p
                        class="text-sm text-muted-foreground"
                        data-testid="socialhq-ai-spend"
                    >
                        <template v-if="spend">
                            {{
                                spend.maxBudget !== null
                                    ? $t('socialhq_ai.budget.spent_of', {
                                          spend: formatUsd(spend.spend),
                                          budget: formatUsd(spend.maxBudget),
                                      })
                                    : $t('socialhq_ai.budget.spent', {
                                          spend: formatUsd(spend.spend),
                                      })
                            }}
                            <template v-if="spend.resetsAt">
                                ·
                                {{
                                    $t('socialhq_ai.budget.resets', {
                                        date: date.formatDate(spend.resetsAt),
                                    })
                                }}
                            </template>
                        </template>
                        <template v-else-if="providers.length === 0">
                            {{ $t('socialhq_ai.budget.no_gateway_yet') }}
                        </template>
                        <template v-else>
                            {{ $t('socialhq_ai.budget.spend_unavailable') }}
                        </template>
                    </p>
                </Deferred>
            </section>

            <section
                class="flex flex-col gap-2 rounded-xl border border-border p-4"
                data-testid="socialhq-ai-storage"
            >
                <h2 class="text-sm font-medium">
                    {{ $t('socialhq_ai.storage.title') }}
                </h2>
                <ul
                    class="list-disc space-y-1 ps-5 text-sm text-muted-foreground"
                >
                    <li>{{ $t('socialhq_ai.storage.keys') }}</li>
                    <li>{{ $t('socialhq_ai.storage.app') }}</li>
                    <li>{{ $t('socialhq_ai.storage.isolation') }}</li>
                </ul>
            </section>
        </div>

        <ProviderKeyDialog
            v-model:open="isKeyDialogOpen"
            :catalogue="catalogue"
            :added="added"
            :replacing="replacing"
        />

        <Dialog v-model:open="isRemoveOpen">
            <DialogContent data-testid="socialhq-ai-remove-dialog">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('socialhq_ai.remove.title', {
                                provider: removing
                                    ? $t(
                                          `socialhq_ai.providers.${removing.provider}.name`,
                                      )
                                    : '',
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('socialhq_ai.remove.description') }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="closeRemove">
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        variant="destructive"
                        :loading="isRemoving"
                        data-testid="socialhq-ai-remove-confirm"
                        @click="confirmRemove"
                    >
                        {{ $t('socialhq_ai.remove.submit') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </SettingsLayout>
</template>
