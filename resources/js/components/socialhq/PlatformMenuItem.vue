<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconBuildingCommunity } from '@tabler/icons-vue';

import { usePlatformAdmin } from '@/components/socialhq/signup';
import { Badge } from '@/components/ui/badge';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { index as platformCompanies } from '@/routes/socialhq/platform/companies';

// Social HQ: the platform admin pages, in the user menu for platform admins only.

const platform = usePlatformAdmin();
</script>

<template>
    <DropdownMenuItem v-if="platform.admin" :as-child="true">
        <Link
            class="flex w-full cursor-pointer items-center gap-2"
            :href="platformCompanies.url()"
            data-testid="socialhq-platform-menu-item"
        >
            <IconBuildingCommunity class="size-4" />
            <span class="flex-1">{{
                $t('socialhq_companies.platform.menu_item')
            }}</span>
            <Badge v-if="platform.pendingCompanies > 0" variant="secondary">
                {{ platform.pendingCompanies }}
            </Badge>
        </Link>
    </DropdownMenuItem>
</template>
