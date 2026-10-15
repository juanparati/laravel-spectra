<script setup>
import Skeleton from '@/components/Skeleton.vue';

defineProps({
    label: {
        type: String,
        required: true,
    },
    // Kept for backwards compatibility with older screens; no longer rendered.
    gradient: {
        type: String,
        default: null,
    },
    // Optional accent dot color (any CSS color).
    accent: {
        type: String,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    periodLabel: {
        type: String,
        default: null,
    },
    skeletonWidth: {
        type: String,
        default: '60%',
    },
});
</script>

<template>
    <div class="stat-card flex flex-col">
        <div class="flex items-center gap-2">
            <span v-if="accent" class="h-2 w-2 rounded-full" :style="{ backgroundColor: accent }" aria-hidden="true"></span>
            <div class="stat-label">{{ label }}</div>
            <div class="ml-auto"><slot name="action" /></div>
        </div>
        <template v-if="loading">
            <Skeleton variant="text" :width="skeletonWidth" height="2rem" class="mt-2" />
        </template>
        <template v-else>
            <slot />
        </template>
        <div v-if="periodLabel" class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ periodLabel }}</div>
        <slot name="footer" />
    </div>
</template>
