<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes/app';

/**
 * 403, 404, 500 and 503 in the app's style (App\SocialHq\Errors\ErrorPages).
 * It reads no shared props: an unmatched URL renders it without them.
 */
const props = defineProps<{
    status: 403 | 404 | 500 | 503;
}>();

const key = computed(() =>
    [403, 404, 500, 503].includes(props.status) ? props.status : 500,
);
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-muted px-4 py-16"
        :data-testid="`socialhq-error-${status}`"
    >
        <Head :title="$t(`socialhq.errors.${key}.title`)" />
        <main
            class="flex w-full max-w-md flex-col items-center gap-6 text-center"
        >
            <AppLogo variant="mark" class="text-[38px]" />
            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ $t('socialhq.errors.code', { status: String(status) }) }}
                </p>
                <h1
                    class="font-heading text-xl font-medium tracking-tight text-balance text-foreground"
                >
                    {{ $t(`socialhq.errors.${key}.title`) }}
                </h1>
                <p class="text-sm text-balance text-muted-foreground">
                    {{ $t(`socialhq.errors.${key}.description`) }}
                </p>
            </div>
            <Button as-child data-testid="socialhq-error-back">
                <Link :href="home()">
                    <IconArrowLeft class="size-4" />
                    {{ $t('socialhq.errors.back') }}
                </Link>
            </Button>
        </main>
    </div>
</template>
