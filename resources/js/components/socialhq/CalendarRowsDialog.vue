<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, ref, watch } from 'vue';

import type {
    PastCalendar,
    PastCalendarRow,
} from '@/components/socialhq/oldCalendars';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import date from '@/date';
import { formatFullNumber } from '@/lib/utils';
import { rows as rowsRoute } from '@/routes/socialhq/brand/calendars';

// Social HQ: the rows of one imported calendar, newest first (the first 200).

const METRICS = ['engagement', 'reach', 'views'] as const;

const props = defineProps<{
    calendar: PastCalendar | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const http = useHttp<
    Record<string, never>,
    { rows: PastCalendarRow[]; total: number }
>({});

const rows = ref<PastCalendarRow[]>([]);
const total = ref(0);
const error = ref<string | null>(null);

const load = async (calendar: PastCalendar): Promise<void> => {
    rows.value = [];
    error.value = null;

    try {
        const response = await http.get(rowsRoute.url(calendar.id));
        rows.value = response?.rows ?? [];
        total.value = response?.total ?? 0;
    } catch {
        error.value = trans('socialhq_calendars.errors.rows_failed');
    }
};

watch(open, (isOpen) => {
    if (isOpen && props.calendar) {
        void load(props.calendar);
    }
});

onBeforeUnmount(() => {
    http.cancel();
});

const close = (): void => {
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-4xl"
            data-testid="socialhq-calendar-rows-dialog"
        >
            <DialogHeader class="text-start">
                <DialogTitle>
                    {{
                        $t('socialhq_calendars.rows_dialog.title', {
                            file: calendar?.file_name ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        total > rows.length
                            ? $t('socialhq_calendars.rows_dialog.description', {
                                  shown: formatFullNumber(rows.length),
                                  total: formatFullNumber(total),
                              })
                            : $t(
                                  'socialhq_calendars.rows_dialog.description_all',
                              )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="http.processing"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
            >
                <Spinner class="size-4" />
                {{ $t('socialhq_calendars.rows_dialog.loading') }}
            </div>
            <p
                v-else-if="error"
                role="alert"
                class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive-text"
            >
                {{ error }}
            </p>
            <div
                v-else
                class="overflow-x-auto rounded-lg border border-border"
                data-testid="socialhq-calendar-rows"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="whitespace-nowrap">
                                {{ $t('socialhq_calendars.rows_dialog.date') }}
                            </TableHead>
                            <TableHead class="whitespace-nowrap">
                                {{
                                    $t('socialhq_calendars.rows_dialog.channel')
                                }}
                            </TableHead>
                            <TableHead class="whitespace-nowrap">
                                {{
                                    $t('socialhq_calendars.rows_dialog.format')
                                }}
                            </TableHead>
                            <TableHead>
                                {{ $t('socialhq_calendars.rows_dialog.post') }}
                            </TableHead>
                            <TableHead class="whitespace-nowrap">
                                {{
                                    $t('socialhq_calendars.rows_dialog.results')
                                }}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in rows" :key="row.id">
                            <TableCell class="align-top whitespace-nowrap">
                                {{ date.formatDateOnly(row.date) }}
                            </TableCell>
                            <TableCell class="align-top">
                                {{ row.channel ?? '' }}
                            </TableCell>
                            <TableCell class="align-top">
                                {{ row.format ?? '' }}
                            </TableCell>
                            <TableCell class="max-w-80 align-top">
                                <div
                                    class="flex flex-col gap-0.5 whitespace-normal"
                                >
                                    <span
                                        v-if="row.title"
                                        class="font-medium text-foreground"
                                    >
                                        {{ row.title }}
                                    </span>
                                    <span
                                        v-if="row.excerpt"
                                        class="line-clamp-2 text-muted-foreground"
                                    >
                                        {{ row.excerpt }}
                                    </span>
                                    <a
                                        v-if="row.link"
                                        :href="row.link"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-primary-text hover:underline"
                                    >
                                        {{
                                            $t(
                                                'socialhq_calendars.rows_dialog.open_link',
                                            )
                                        }}
                                        <IconExternalLink class="size-3.5" />
                                    </a>
                                </div>
                            </TableCell>
                            <TableCell
                                class="align-top whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                <template
                                    v-for="metric in METRICS"
                                    :key="metric"
                                >
                                    <div v-if="row[metric] !== null">
                                        {{
                                            $t(
                                                `socialhq_calendars.metrics.${metric}`,
                                                {
                                                    count: formatFullNumber(
                                                        row[metric] ?? 0,
                                                    ),
                                                },
                                            )
                                        }}
                                    </div>
                                </template>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="close">
                    {{ $t('socialhq_calendars.close') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
