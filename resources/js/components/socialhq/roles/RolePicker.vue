<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';

import RoleBadges from '@/components/socialhq/roles/RoleBadges.vue';
import type { CompanyRole } from '@/components/socialhq/roles/roles';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { compare as compareRoles } from '@/routes/socialhq/roles';

const props = defineProps<{
    memberId: string;
    memberName: string;
    current: string | null;
    label: string;
    roles: CompanyRole[];
}>();

const emit = defineEmits<{ pick: [role: CompanyRole] }>();

// A role above the viewer's access stays in the list, dimmed, with the reason on hover.
const pick = (role: CompanyRole, event: Event): void => {
    if (!role.assignable) {
        event.preventDefault();

        return;
    }

    if (role.id !== props.current) {
        emit('pick', role);
    }
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                class="h-9 min-w-[150px] justify-between gap-2 px-3 text-[13px] font-medium"
                :aria-label="
                    $t('socialhq_roles.members.menu.label', {
                        name: memberName,
                    })
                "
                :data-testid="`member-role-${memberId}`"
            >
                <span class="truncate">{{ label }}</span>
                <IconChevronDown class="size-3.5 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            class="w-[320px] p-1.5"
            :data-testid="`member-role-menu-${memberId}`"
        >
            <DropdownMenuItem
                v-for="role in roles"
                :key="role.id"
                class="items-start gap-2.5 px-2.5 py-2"
                :class="{ 'cursor-not-allowed opacity-50': !role.assignable }"
                :aria-disabled="!role.assignable"
                :title="
                    role.assignable
                        ? undefined
                        : $t('socialhq_roles.members.menu.above')
                "
                :data-testid="`member-role-option-${role.key ?? role.id}`"
                @select="pick(role, $event)"
            >
                <IconCheck
                    class="mt-0.5 size-4 shrink-0"
                    :class="
                        role.id === current
                            ? 'text-primary-text'
                            : 'text-transparent'
                    "
                />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="flex items-center gap-1.5 text-[13.5px] font-emphasis"
                    >
                        {{ role.name }}
                        <RoleBadges :role="role" custom-only />
                    </span>
                    <span
                        v-if="role.description"
                        class="text-[12.5px] leading-snug text-muted-foreground"
                        >{{ role.description }}</span
                    >
                </span>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <div class="px-2.5 pt-1 pb-1">
                <Link
                    :href="compareRoles()"
                    class="text-[12.5px] font-medium text-primary-text hover:underline"
                    data-testid="member-role-compare"
                >
                    {{ $t('socialhq_roles.members.menu.compare') }} →
                </Link>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
