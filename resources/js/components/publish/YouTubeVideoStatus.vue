<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

import { show as showGroup } from '@/actions/App/Http/Controllers/App/PostGroupController';
import { Button } from '@/components/ui/button';
import { filled } from '@/lib/helpers';
import { youtubeExtras as retryExtras } from '@/routes/app/posts';
import type { PostCard } from '@/types/publish';

interface Operation {
    kind: string;
    label: string;
    status: string;
    error?: string | null;
}

const props = defineProps<{
    postId: string;
    meta: Record<string, unknown>;
    canRetry: boolean;
}>();
const operations = ref<Record<string, Operation>>({});
watch(
    () => props.meta,
    (meta) => {
        if (filled(meta.youtube_operations)) {
            operations.value = meta.youtube_operations as Record<
                string,
                Operation
            >;
        }
    },
    { immediate: true },
);
const reads = useHttp<Record<string, never>, PostCard[]>({});
const writes = useHttp<Record<string, never>, { queued: boolean }>({});
const queued = ref(false);
const failed = ref(false);
const rows = computed(() =>
    Object.entries(operations.value).map(([key, operation]) => {
        return {
            key,
            ...operation,
            kindKey: `posts.form.youtube.operation_${operation.kind}`,
            statusKey: `posts.form.youtube.status_${operation.status}`,
        };
    }),
);
const hasRows = computed(() => filled(rows.value));
const hasUnfinished = computed(() => {
    for (const operation of Object.values(operations.value)) {
        if (operation.status !== 'completed') {
            return true;
        }
    }
    return !hasRows.value;
});
const canRetry = computed(() => {
    if (!props.canRetry) {
        return false;
    }
    return hasUnfinished.value;
});
const refresh = async (): Promise<void> => {
    try {
        const posts = await reads.get(showGroup.url(props.postId));
        const post = posts?.find((item) => item.id === props.postId);
        const latest = post?.meta?.youtube_operations;
        if (filled(latest)) {
            operations.value = latest;
        }
    } catch {
        failed.value = true;
    }
};
const retry = async (): Promise<void> => {
    failed.value = false;
    try {
        await writes.post(retryExtras.url(props.postId));
        queued.value = true;
        await refresh();
    } catch {
        failed.value = true;
    }
};
useIntervalFn(refresh, 10000);
</script>

<template>
    <section
        class="space-y-3 rounded-lg border p-3"
        data-testid="youtube-video-status"
    >
        <h3 class="text-sm font-semibold">
            {{ $t('posts.form.youtube.extras_title') }}
        </h3>
        <p v-if="!hasRows" class="text-sm text-muted-foreground">
            {{ $t('posts.form.youtube.status_pending') }}
        </p>
        <div
            v-for="operation in rows"
            :key="operation.key"
            class="space-y-1 text-sm"
        >
            <p>
                {{ $t(operation.kindKey) }} · {{ operation.label }} ·
                {{ $t(operation.statusKey) }}
            </p>
            <p v-if="operation.error" class="text-destructive">
                {{ operation.error }}
            </p>
        </div>
        <p v-if="queued" class="text-sm" role="status">
            {{ $t('posts.form.youtube.status_pending') }}
        </p>
        <p v-if="failed" class="text-sm text-destructive">
            {{ $t('posts.form.youtube.operation_failed') }}
        </p>
        <Button
            v-if="canRetry"
            data-testid="youtube-extras-retry"
            type="button"
            variant="outline"
            size="sm"
            :loading="writes.processing"
            @click="retry"
            >{{ $t('posts.form.youtube.retry') }}</Button
        >
    </section>
</template>
