<script setup lang="ts">
import { IconMapPin } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import type { AudienceProp } from '@/components/socialhq/audience';
import AudienceDialog from '@/components/socialhq/AudienceDialog.vue';
import { placeOfZone } from '@/components/socialhq/plan/postingTime';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    audience: AudienceProp;
    canEdit: boolean;
}>();

const isOpen = ref(false);

const countryName = computed(
    () =>
        new Map(
            props.audience.countries.map((country) => [
                country.code,
                country.name,
            ]),
        ),
);

const total = computed(() =>
    props.audience.locations.reduce((sum, location) => sum + location.share, 0),
);

const openDialog = (): void => {
    isOpen.value = true;
};
</script>

<template>
    <section
        id="audience"
        class="flex flex-col gap-4"
        data-testid="socialhq-brand-audience"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h2 class="text-base font-semibold text-foreground">
                    {{ $t('socialhq_audience.title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ $t('socialhq_audience.description') }}
                </p>
            </div>
            <Button
                v-if="canEdit"
                variant="outline"
                class="shrink-0"
                :aria-label="$t('socialhq_audience.edit_label')"
                data-testid="socialhq-brand-audience-edit"
                @click="openDialog"
            >
                {{ $t('socialhq_audience.edit') }}
            </Button>
        </div>

        <div class="flex flex-col gap-3 rounded-lg border bg-card p-4">
            <template v-if="audience.locations.length">
                <ul
                    class="flex flex-col divide-y divide-border"
                    data-testid="socialhq-brand-audience-list"
                >
                    <li
                        v-for="location in audience.locations"
                        :key="`${location.country}-${location.city ?? ''}`"
                        class="flex flex-col gap-1.5 py-2 first:pt-0"
                    >
                        <div class="flex items-center gap-3 text-sm">
                            <IconMapPin
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span
                                class="min-w-0 flex-1 truncate text-foreground"
                            >
                                {{
                                    [
                                        location.city,
                                        countryName.get(location.country) ??
                                            location.country,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')
                                }}
                                <span class="text-muted-foreground">
                                    · {{ placeOfZone(location.timezone) }}
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-muted-foreground tabular-nums"
                                >{{
                                    $t('socialhq_audience.share', {
                                        share: String(location.share),
                                    })
                                }}</span
                            >
                        </div>
                        <div
                            class="ms-7 h-1.5 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{ width: `${location.share}%` }"
                            />
                        </div>
                    </li>
                </ul>
                <p class="border-t pt-3 text-sm text-muted-foreground">
                    {{
                        $t('socialhq_audience.main_help', {
                            zone: audience.mainTimezone,
                        })
                    }}
                    {{
                        $t('socialhq_audience.total', {
                            total: String(total),
                        })
                    }}
                </p>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    $t(
                        canEdit
                            ? 'socialhq_audience.empty'
                            : 'socialhq_audience.empty_member',
                        { zone: audience.mainTimezone },
                    )
                }}
            </p>
        </div>

        <AudienceDialog
            v-if="canEdit"
            v-model:open="isOpen"
            :audience="audience"
        />
    </section>
</template>
