<script setup lang="ts">
import { computed } from 'vue';
import { formatBytes } from '@/lib/format';

const props = defineProps<{
    used: number;
    total: number | null;
    warningPercent?: number;
}>();

const percent = computed(() => {
    if (props.total === null || props.total === 0) {
        return null;
    }
    return Math.min(100, (props.used / props.total) * 100);
});

const nearlyFull = computed(
    () => percent.value !== null && percent.value >= (props.warningPercent ?? 90),
);

const label = computed(() => {
    if (props.total === null) {
        return `${formatBytes(props.used)} / unlimited`;
    }
    return `${formatBytes(props.used)} / ${formatBytes(props.total)}`;
});
</script>

<template>
    <div class="space-y-1">
        <div class="bg-muted h-1.5 w-full overflow-hidden rounded-full">
            <div
                v-if="percent !== null"
                class="h-full rounded-full transition-[width] duration-500"
                :class="nearlyFull ? 'bg-red-500' : 'bg-teal-600 dark:bg-teal-400'"
                :style="{ width: `${percent}%` }"
                role="progressbar"
                :aria-valuenow="Math.round(percent)"
                aria-valuemin="0"
                aria-valuemax="100"
            />
        </div>
        <p class="text-muted-foreground text-xs">{{ label }}</p>
    </div>
</template>
