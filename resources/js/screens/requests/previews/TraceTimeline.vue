<script setup>
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import ProviderLogo from '@/components/ProviderLogo.vue';

const props = defineProps({
    trace: { type: Object, required: true },
});

const { formatNumber, formatCurrency, formatDuration } = useHelpers();

// Bars share one time scale: the whole trace, at least 1ms so nothing divides by zero.
const span = computed(() => Math.max(1, props.trace.duration_ms || 0));

const steps = computed(() => (props.trace.requests || []).map((step, index) => {
    const offset = step.offset_ms || 0;
    const length = step.latency_ms || 0;

    return {
        ...step,
        number: index + 1,
        left: Math.min(100, (offset / span.value) * 100),
        width: Math.max(0.8, Math.min(100 - (offset / span.value) * 100, (length / span.value) * 100)),
        startLabel: offset > 0 ? '+' + formatDuration(offset) : 'Start',
    };
}));

const failedCount = computed(() => steps.value.filter((step) => step.is_error).length);
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <div class="flex min-w-0 items-center gap-2.5">
                <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                </svg>
                <h3>Trace</h3>
                <span class="truncate text-xs text-gray-500 dark:text-gray-400">Call {{ trace.position }} of {{ trace.request_count }}</span>
            </div>
            <router-link
                :to="{ name: 'requests', query: { trace_id: trace.trace_id } }"
                class="shrink-0 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
            >View in requests</router-link>
        </div>

        <!-- Totals for the whole trace -->
        <dl class="grid grid-cols-3 divide-x divide-gray-100 border-b border-gray-100 dark:divide-gray-800 dark:border-gray-800">
            <div class="px-5 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Total cost</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatCurrency(trace.total_cost_in_cents) }}</dd>
            </div>
            <div class="px-5 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Total tokens</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(trace.total_tokens) }}</dd>
            </div>
            <div class="px-5 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Duration</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                    {{ formatDuration(trace.duration_ms) }}
                    <span v-if="failedCount" class="ml-1 text-xs font-medium text-red-600 dark:text-red-400">· {{ failedCount }} failed</span>
                </dd>
            </div>
        </dl>

        <ol class="divide-y divide-gray-100 dark:divide-gray-800/80">
            <li v-for="step in steps" :key="step.id">
                <router-link
                    :to="{ name: 'request-preview', params: { id: step.id } }"
                    :aria-current="step.is_current ? 'page' : undefined"
                    class="group relative grid grid-cols-[1.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 px-5 py-2.5 transition-colors sm:grid-cols-[1.5rem_minmax(0,13rem)_minmax(0,1fr)_4.5rem_4.5rem]"
                    :class="step.is_current
                        ? 'bg-primary-50/70 dark:bg-primary-500/[0.08]'
                        : 'hover:bg-gray-50 dark:hover:bg-white/[0.03]'"
                >
                    <span
                        v-if="step.is_current"
                        class="absolute inset-y-0 left-0 w-0.5 bg-primary-500"
                        aria-hidden="true"
                    ></span>

                    <span
                        class="col-start-1 row-start-1 text-xs tabular-nums sm:col-start-auto sm:row-start-auto"
                        :class="step.is_current ? 'font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-400 dark:text-gray-500'"
                    >{{ step.number }}</span>

                    <span class="col-start-2 row-start-1 flex min-w-0 items-center gap-2 sm:col-start-auto sm:row-start-auto">
                        <ProviderLogo
                            :provider="step.provider"
                            :title="step.provider_display_name || step.provider"
                            class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                        />
                        <span
                            class="truncate text-sm"
                            :class="step.is_current ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-200'"
                            :title="step.model"
                        >{{ step.model }}</span>
                        <span v-if="step.is_error" class="badge badge-danger shrink-0 !px-1.5 !py-0 text-[11px]">
                            <svg class="mr-0.5 h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                            Failed
                        </span>
                    </span>

                    <!-- Waterfall: where this call sits in the trace -->
                    <span class="relative col-span-2 col-start-2 row-start-2 h-5 sm:col-span-1 sm:col-start-auto sm:row-start-auto">
                        <span class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-gray-100 dark:bg-gray-800" aria-hidden="true"></span>
                        <span
                            class="absolute top-1/2 h-2 -translate-y-1/2 rounded-[4px] ring-2 ring-white transition-colors dark:ring-gray-900"
                            :class="[
                                step.is_error
                                    ? 'bg-red-500 dark:bg-red-400'
                                    : step.is_current
                                        ? 'bg-primary-600 dark:bg-primary-400'
                                        : 'bg-primary-300 group-hover:bg-primary-400 dark:bg-primary-500/60 dark:group-hover:bg-primary-400/80',
                            ]"
                            :style="{ left: step.left + '%', width: step.width + '%' }"
                        ></span>
                        <!-- Hover details -->
                        <span
                            class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-pop group-hover:block dark:bg-gray-100 dark:text-gray-900"
                            :style="{ left: `min(${step.left}%, calc(100% - 9rem))` }"
                        >
                            {{ step.startLabel }} · {{ formatDuration(step.latency_ms) }}<template v-if="step.is_streaming"> · streamed</template>
                        </span>
                    </span>

                    <span class="col-start-3 row-start-1 text-right text-xs tabular-nums text-gray-500 sm:col-start-auto sm:row-start-auto dark:text-gray-400">{{ formatDuration(step.latency_ms) }}</span>
                    <span class="hidden text-right text-xs font-medium tabular-nums text-gray-700 sm:block dark:text-gray-200">{{ formatCurrency(step.total_cost_in_cents) }}</span>
                </router-link>
            </li>
        </ol>

        <div v-if="trace.truncated" class="border-t border-gray-100 px-5 py-2.5 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
            Showing the first {{ steps.length }} of {{ formatNumber(trace.request_count) }} calls.
            <router-link :to="{ name: 'requests', query: { trace_id: trace.trace_id } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">See all</router-link>
        </div>
    </div>
</template>
