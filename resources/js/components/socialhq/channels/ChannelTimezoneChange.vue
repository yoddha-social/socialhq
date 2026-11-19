<script setup lang="ts">
import { computed, ref } from 'vue';

import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { TimezoneOption } from '@/types/posting-schedule';

const props = defineProps<{
    timezone: string;
    timezones: TimezoneOption[];
}>();

const emit = defineEmits<{
    change: [timezone: string];
}>();

const pendingTimezone = ref<string | null>(null);

const selectedTimezone = computed({
    get: () => props.timezone,
    set: (value: string) => {
        if (value !== props.timezone) {
            pendingTimezone.value = value;
        }
    },
});

const confirmOpen = computed({
    get: () => pendingTimezone.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingTimezone.value = null;
        }
    },
});

const closeConfirmDialog = (): void => {
    confirmOpen.value = false;
};

const confirmTimezone = (): void => {
    const value = pendingTimezone.value;
    pendingTimezone.value = null;

    if (value) {
        emit('change', value);
    }
};
</script>

<template>
    <div>
        <TimezoneSelect
            v-model="selectedTimezone"
            :options="timezones"
            :trigger-label="$t('socialhq.channels.timezone.action')"
            testid="channel-timezone"
            compact
        />

        <Dialog v-model:open="confirmOpen">
            <DialogContent
                :show-close-button="false"
                data-testid="channel-timezone-confirm-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('channels.settings_page.timezone_confirm_title')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'channels.settings_page.timezone_confirm_description',
                                { timezone: pendingTimezone ?? '' },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="channel-timezone-confirm-cancel"
                        @click="closeConfirmDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="channel-timezone-confirm-submit"
                        @click="confirmTimezone"
                    >
                        {{ $t('channels.settings_page.timezone_confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
