<script setup lang="ts">
import { IconGitCompare, IconHistory, IconSparkles } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import type { CopyVersion } from '@/components/socialhq/plan/item/types';
import { wordDiff } from '@/components/socialhq/plan/item/wordDiff';
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
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import date from '@/date';

const props = defineProps<{
    versions: CopyVersion[];
    canEdit: boolean;
}>();

const emit = defineEmits<{
    use: [version: CopyVersion];
}>();

const newestFirst = computed(() => [...props.versions].reverse());
const latestNumber = computed(
    () => props.versions[props.versions.length - 1]?.number ?? 0,
);

const open = ref(false);
const viewing = ref<CopyVersion | null>(null);
/** The version number shown against `viewing`; null shows the version alone. */
const compareWith = ref<number | null>(null);

const versionText = (version: CopyVersion): string =>
    [version.caption, version.hashtags, version.script, version.notes]
        .filter((part): part is string => Boolean(part && part.trim()))
        .join('\n\n');

const other = computed(
    () =>
        props.versions.find(
            (version) => version.number === compareWith.value,
        ) ?? null,
);

const diff = computed(() => {
    if (!viewing.value || !other.value) {
        return [];
    }

    const [older, newer] =
        other.value.number < viewing.value.number
            ? [other.value, viewing.value]
            : [viewing.value, other.value];

    return wordDiff(versionText(older), versionText(newer));
});

const hasChanges = computed(() =>
    diff.value.some((part) => part.change !== 'same'),
);

const openVersion = (version: CopyVersion): void => {
    viewing.value = version;
    compareWith.value = null;
    open.value = true;
};

const openCompare = (version: CopyVersion): void => {
    viewing.value = version;
    // The version just before it, or the next one for version 1.
    compareWith.value =
        [...props.versions]
            .reverse()
            .find((candidate) => candidate.number < version.number)?.number ??
        props.versions.find((candidate) => candidate.number > version.number)
            ?.number ??
        null;
    open.value = true;
};

const setOpen = (isOpen: boolean): void => {
    open.value = isOpen;
};

const close = (): void => {
    open.value = false;
};

const setCompareWith = (value: unknown): void => {
    compareWith.value = value === '' || value === null ? null : Number(value);
};

const useVersion = (): void => {
    if (viewing.value) {
        emit('use', viewing.value);
    }

    open.value = false;
};
</script>

<template>
    <section
        class="rounded-xl border bg-card px-5 py-4"
        aria-labelledby="socialhq-versions-title"
        data-testid="item-versions"
    >
        <h2
            id="socialhq-versions-title"
            class="flex items-center gap-2 text-base font-semibold"
        >
            <IconHistory class="size-4 text-muted-foreground" />
            {{ $t('socialhq_production.item.versions.title') }}
        </h2>
        <p
            v-if="versions.length === 0"
            class="mt-2 text-sm text-muted-foreground"
        >
            {{ $t('socialhq_production.item.versions.empty') }}
        </p>
        <ol v-else class="mt-3 divide-y">
            <li
                v-for="version in newestFirst"
                :key="version.id"
                class="flex flex-wrap items-center justify-between gap-2 py-2.5"
                :data-testid="`item-version-${version.number}`"
            >
                <div class="min-w-0">
                    <p class="flex items-center gap-2 text-sm font-medium">
                        {{
                            $t('socialhq_production.item.versions.label', {
                                number: String(version.number),
                            })
                        }}
                        <Badge
                            :variant="
                                version.source === 'ai' ? 'info' : 'secondary'
                            "
                        >
                            <IconSparkles v-if="version.source === 'ai'" />
                            {{
                                $t(
                                    `socialhq_production.item.versions.source.${version.source}`,
                                )
                            }}
                        </Badge>
                        <Badge
                            v-if="version.number === latestNumber"
                            variant="outline"
                        >
                            {{
                                $t('socialhq_production.item.versions.current')
                            }}
                        </Badge>
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{
                            $t('socialhq_production.item.versions.by', {
                                name: version.author?.name ?? '—',
                                time: date.formatDateTime(version.created_at),
                            })
                        }}
                    </p>
                </div>
                <div class="flex gap-1">
                    <Button
                        size="sm"
                        variant="ghost"
                        :data-testid="`item-version-view-${version.number}`"
                        @click="openVersion(version)"
                    >
                        {{ $t('socialhq_production.item.versions.view') }}
                    </Button>
                    <Button
                        v-if="versions.length > 1"
                        size="sm"
                        variant="ghost"
                        :data-testid="`item-version-compare-${version.number}`"
                        @click="openCompare(version)"
                    >
                        <IconGitCompare />
                        {{ $t('socialhq_production.item.versions.compare') }}
                    </Button>
                </div>
            </li>
        </ol>

        <Dialog :open="open" @update:open="setOpen">
            <DialogContent
                class="max-h-[85dvh] overflow-y-auto sm:max-w-2xl"
                data-testid="item-version-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            viewing
                                ? $t(
                                      'socialhq_production.item.versions.label',
                                      {
                                          number: String(viewing.number),
                                      },
                                  )
                                : ''
                        }}
                    </DialogTitle>
                    <DialogDescription v-if="viewing">
                        {{
                            $t('socialhq_production.item.versions.by', {
                                name: viewing.author?.name ?? '—',
                                time: date.formatDateTime(viewing.created_at),
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="viewing && versions.length > 1"
                    class="flex items-center gap-2"
                >
                    <Label for="socialhq-version-compare" class="shrink-0">
                        {{
                            $t('socialhq_production.item.versions.compare_with')
                        }}
                    </Label>
                    <NativeSelect
                        id="socialhq-version-compare"
                        :model-value="compareWith ?? ''"
                        data-testid="item-version-compare-select"
                        @update:model-value="setCompareWith"
                    >
                        <NativeSelectOption value="">—</NativeSelectOption>
                        <NativeSelectOption
                            v-for="candidate in newestFirst.filter(
                                (version) => version.number !== viewing?.number,
                            )"
                            :key="candidate.id"
                            :value="candidate.number"
                        >
                            {{
                                $t('socialhq_production.item.versions.label', {
                                    number: String(candidate.number),
                                })
                            }}
                        </NativeSelectOption>
                    </NativeSelect>
                </div>

                <div
                    v-if="viewing && other"
                    class="rounded-lg border bg-background p-3 text-sm leading-relaxed whitespace-pre-wrap"
                    data-testid="item-version-diff"
                >
                    <template v-if="hasChanges">
                        <span
                            v-for="(part, index) in diff"
                            :key="index"
                            :class="{
                                'rounded-sm bg-success-subtle text-success-text':
                                    part.change === 'added',
                                'rounded-sm bg-destructive/10 text-destructive-text line-through':
                                    part.change === 'removed',
                            }"
                            >{{ part.text }}</span
                        >
                    </template>
                    <span v-else class="text-muted-foreground">
                        {{ $t('socialhq_production.item.versions.no_changes') }}
                    </span>
                </div>
                <dl v-else-if="viewing" class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-muted-foreground">
                            {{ $t('socialhq_production.item.copy.caption') }}
                        </dt>
                        <dd class="leading-relaxed whitespace-pre-wrap">
                            {{ viewing.caption }}
                        </dd>
                    </div>
                    <div v-if="viewing.hashtags">
                        <dt class="text-xs font-medium text-muted-foreground">
                            {{ $t('socialhq_production.item.copy.hashtags') }}
                        </dt>
                        <dd>{{ viewing.hashtags }}</dd>
                    </div>
                    <div v-if="viewing.script">
                        <dt class="text-xs font-medium text-muted-foreground">
                            {{ $t('socialhq_production.item.copy.script') }}
                        </dt>
                        <dd class="font-mono text-[13px] whitespace-pre-wrap">
                            {{ viewing.script }}
                        </dd>
                    </div>
                    <div v-if="viewing.notes">
                        <dt class="text-xs font-medium text-muted-foreground">
                            {{ $t('socialhq_production.item.copy.notes') }}
                        </dt>
                        <dd class="whitespace-pre-wrap">{{ viewing.notes }}</dd>
                    </div>
                </dl>

                <DialogFooter>
                    <Button variant="outline" @click="close">
                        {{ $t('socialhq_production.item.versions.close') }}
                    </Button>
                    <Button
                        v-if="
                            canEdit &&
                            viewing &&
                            viewing.number !== latestNumber
                        "
                        data-testid="item-version-use"
                        @click="useVersion"
                    >
                        {{ $t('socialhq_production.item.versions.use') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
