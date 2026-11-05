<script setup lang="ts">
import { IconShieldCheck } from '@tabler/icons-vue';

import { Label } from '@/components/ui/label';
</script>

<template>
    <div class="grid gap-2" data-testid="socialhq-members-reviewed">
        <Label>{{ $t('settings.members.access.publishing') }}</Label>
        <p
            class="flex items-start gap-2 rounded-lg border border-border px-3 py-2 text-sm text-muted-foreground"
        >
            <IconShieldCheck class="mt-0.5 size-4 shrink-0 text-foreground" />
            <span>{{ $t('socialhq.approvals.members_note') }}</span>
        </p>
    </div>
</template>
