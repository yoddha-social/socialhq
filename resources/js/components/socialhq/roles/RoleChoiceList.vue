<script setup lang="ts">
import RoleBadges from '@/components/socialhq/roles/RoleBadges.vue';
import type { CompanyRole } from '@/components/socialhq/roles/roles';

const props = defineProps<{
    roles: CompanyRole[];
    /** Whether the viewer may give this role (dimmed and not selectable otherwise). */
    available: (role: CompanyRole) => boolean;
    labelledBy?: string;
}>();

const selected = defineModel<string | null>({ required: true });

const choose = (role: CompanyRole): void => {
    if (props.available(role)) {
        selected.value = role.id;
    }
};
</script>

<template>
    <div
        role="radiogroup"
        class="flex flex-col gap-1.5"
        :aria-labelledby="labelledBy"
        data-testid="role-choice-list"
    >
        <button
            v-for="role in roles"
            :key="role.id"
            type="button"
            role="radio"
            :aria-checked="selected === role.id"
            :aria-disabled="!available(role)"
            :title="
                available(role)
                    ? undefined
                    : $t('socialhq_roles.members.menu.above')
            "
            class="flex items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition-colors"
            :class="[
                selected === role.id
                    ? 'border-primary-text bg-primary-subtle/40'
                    : 'border-border',
                available(role)
                    ? 'hover:bg-accent'
                    : 'cursor-not-allowed opacity-50',
            ]"
            :data-testid="`role-choice-${role.key ?? role.id}`"
            @click="choose(role)"
        >
            <span
                class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full border"
                :class="
                    selected === role.id
                        ? 'border-primary-text'
                        : 'border-input'
                "
            >
                <span
                    v-if="selected === role.id"
                    class="size-2 rounded-full bg-primary-text"
                />
            </span>
            <span class="flex min-w-0 flex-col">
                <span
                    class="flex items-center gap-1.5 text-[13.5px] font-emphasis"
                >
                    {{ role.name }}
                    <RoleBadges :role="role" custom-only />
                </span>
                <span
                    v-if="role.description"
                    class="text-[12.5px] text-muted-foreground"
                    >{{ role.description }}</span
                >
            </span>
        </button>
    </div>
</template>
