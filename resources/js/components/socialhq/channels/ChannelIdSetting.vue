<script setup lang="ts">
import { IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import { Button } from '@/components/ui/button';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    channelId: string;
}>();

const copyId = (): void => {
    void copyToClipboard(props.channelId, trans('socialhq.channels.id_copied'));
};
</script>

<template>
    <section
        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
        data-testid="channel-id-setting"
    >
        <div class="min-w-0">
            <h2 class="text-base leading-5 font-emphasis text-foreground">
                {{ $t('socialhq.channels.id_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ $t('socialhq.channels.id_description') }}
            </p>
        </div>
        <div
            class="flex min-w-0 shrink-0 items-center gap-2 self-start md:self-auto"
        >
            <code
                class="min-w-0 truncate rounded-md bg-muted px-2 py-1 font-mono text-xs text-muted-foreground"
                data-testid="channel-id-value"
                >{{ channelId }}</code
            >
            <Button
                variant="outline"
                class="shrink-0"
                data-testid="channel-id-copy"
                @click="copyId"
            >
                <IconCopy class="size-4" />
                {{ $t('socialhq.channels.id_copy') }}
            </Button>
        </div>
    </section>
</template>
