<script setup lang="ts">
import { ref } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { pillarColorAt } from '@/components/socialhq/plan/pillarColors';
import PlanBrandSettingsDialog from '@/components/socialhq/plan/PlanBrandSettingsDialog.vue';
import type { PlanChannel } from '@/components/socialhq/plan/types';
import { Button } from '@/components/ui/button';

defineProps<{
    pillars: string[];
    cadence: Record<string, number | null>;
    channels: PlanChannel[];
    canEdit: boolean;
}>();

const isOpen = ref(false);

const openSettings = (): void => {
    isOpen.value = true;
};
</script>

<template>
    <section
        id="planning"
        class="flex flex-col gap-4"
        data-testid="socialhq-brand-planning"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h2 class="text-base font-semibold text-foreground">
                    {{ $t('socialhq.brand.planning.title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ $t('socialhq.brand.planning.description') }}
                </p>
            </div>
            <Button
                v-if="canEdit"
                variant="outline"
                class="shrink-0"
                :aria-label="$t('socialhq.brand.planning.edit_label')"
                data-testid="socialhq-brand-planning-edit"
                @click="openSettings"
            >
                {{ $t('socialhq.brand.planning.edit') }}
            </Button>
        </div>

        <div class="flex flex-col gap-4 rounded-lg border bg-card p-4">
            <ul
                v-if="pillars.length"
                class="flex flex-wrap gap-2"
                data-testid="socialhq-brand-pillars"
            >
                <li
                    v-for="(pillar, index) in pillars"
                    :key="pillar"
                    class="flex items-center gap-2 rounded-full border px-3 py-1 text-sm text-foreground"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :class="pillarColorAt(index).dot"
                        aria-hidden="true"
                    />
                    {{ pillar }}
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ $t('socialhq.brand.planning.no_pillars') }}
            </p>

            <ul
                v-if="channels.length"
                class="flex flex-col divide-y divide-border border-t"
                data-testid="socialhq-brand-cadence"
            >
                <li
                    v-for="channel in channels"
                    :key="channel.id"
                    class="flex items-center gap-3 py-2 text-sm"
                >
                    <PlatformBrandIcon
                        :platform="channel.platform"
                        colored
                        class="size-4 shrink-0"
                    />
                    <span class="min-w-0 flex-1 truncate text-foreground">{{
                        channel.name
                    }}</span>
                    <span class="shrink-0 text-muted-foreground tabular-nums">
                        {{
                            cadence[channel.id] === null ||
                            cadence[channel.id] === undefined
                                ? $t('socialhq.brand.planning.not_set')
                                : $t('socialhq.brand.planning.per_week', {
                                      count: String(cadence[channel.id]),
                                  })
                        }}
                    </span>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ $t('socialhq.brand.planning.no_channels') }}
            </p>
        </div>

        <PlanBrandSettingsDialog
            v-if="canEdit"
            v-model:open="isOpen"
            :pillars="pillars"
            :cadence="cadence"
            :channels="channels"
        />
    </section>
</template>
