<script setup lang="ts">
// AGPL-3.0 section 13: users of this modified TryPost can get its source.
const sourceUrl =
    import.meta.env.VITE_SOCIALHQ_SOURCE_URL ||
    'https://github.com/yoddha-social/socialhq';
</script>

<template>
    <a
        :href="sourceUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="block px-4 py-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden hover:text-foreground"
    >
        Source code (AGPL-3.0)
    </a>
</template>
