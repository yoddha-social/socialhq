<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconAlertCircle, IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import { orderChannels } from '@/composables/useChannelOrder';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { settings as settingsRoute } from '@/routes/app/channels';
import type { SidebarChannel } from '@/types/channel';
import { isConnectionLost } from '@/types/social-account';

defineProps<{
    activeId: string;
}>();

const page = usePage();
const channels = computed<SidebarChannel[]>(() =>
    orderChannels((page.props.channels as SidebarChannel[] | undefined) ?? []),
);

const { open } = useConnectChannelDialog();

const connect = (): void => {
    open();
};
</script>

<template>
    <aside
        class="w-[280px] shrink-0 overflow-y-auto border-e border-border bg-muted/40 p-4"
        data-testid="channel-side-list"
    >
        <p
            class="mb-3 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
        >
            {{
                $t('socialhq.channels.list_title', {
                    count: String(channels.length),
                })
            }}
        </p>
        <Button
            variant="outline"
            class="mb-3 w-full bg-card"
            data-testid="channel-side-connect"
            @click="connect"
        >
            <IconPlus class="size-4" />
            {{ $t('socialhq.channels.connect') }}
        </Button>
        <nav class="flex flex-col gap-1">
            <Link
                v-for="channel in channels"
                :key="channel.id"
                :href="settingsRoute.url(channel.id)"
                class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :class="
                    channel.id === activeId
                        ? 'bg-card shadow-sm ring-1 ring-border'
                        : 'hover:bg-card/70'
                "
                :aria-current="channel.id === activeId ? 'page' : undefined"
                :data-testid="`channel-side-item-${channel.id}`"
            >
                <ChannelAvatar
                    :platform="channel.platform"
                    :src="channel.avatar_url"
                    :name="channel.display_label"
                    :size="32"
                    :ring="channel.id === activeId ? 'card' : 'background'"
                />
                <span class="min-w-0 flex-1 leading-tight">
                    <span
                        class="block truncate text-[13px] font-emphasis text-foreground"
                        >{{ channel.display_label }}</span
                    >
                    <span
                        class="block truncate text-[11.5px] text-muted-foreground"
                        >{{ getPlatformLabel(channel.platform) }}</span
                    >
                </span>
                <IconAlertCircle
                    v-if="isConnectionLost(channel)"
                    class="size-4 shrink-0 text-destructive"
                    :data-testid="`channel-side-lost-${channel.id}`"
                />
            </Link>
        </nav>
    </aside>
</template>
