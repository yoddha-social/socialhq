<script setup lang="ts">
import { IconLock } from '@tabler/icons-vue';

import type { CompanyRole } from '@/components/socialhq/roles/roles';
import { Badge } from '@/components/ui/badge';

defineProps<{
    role: Pick<CompanyRole, 'built_in' | 'fixed' | 'changed'>;
    /** Only "Custom" (menus, where built-in is the norm). */
    customOnly?: boolean;
}>();
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <template v-if="!customOnly">
            <Badge
                v-if="role.fixed"
                variant="secondary"
                class="h-[22px] px-2"
                data-testid="role-badge-fixed"
            >
                <IconLock />{{ $t('socialhq_roles.badges.fixed') }}
            </Badge>
            <Badge
                v-else-if="role.built_in"
                variant="secondary"
                class="h-[22px] px-2"
                data-testid="role-badge-built-in"
            >
                {{ $t('socialhq_roles.badges.built_in') }}
            </Badge>
        </template>
        <Badge
            v-if="!role.built_in"
            variant="secondary"
            class="h-[22px] bg-primary-subtle px-2 text-primary-text"
            data-testid="role-badge-custom"
        >
            {{ $t('socialhq_roles.badges.custom') }}
        </Badge>
        <Badge
            v-if="!customOnly && role.changed"
            variant="warning"
            class="h-[22px] px-2"
            data-testid="role-badge-changed"
        >
            {{ $t('socialhq_roles.badges.changed') }}
        </Badge>
    </span>
</template>
