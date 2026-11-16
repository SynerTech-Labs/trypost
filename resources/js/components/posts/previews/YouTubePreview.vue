<script setup lang="ts">
import {
    IconBrandYoutubeFilled,
    IconMessage2,
    IconShare3,
    IconThumbDown,
    IconThumbUp,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PostMediaPreview from '@/components/posts/previews/PostMediaPreview.vue';
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import PreviewText from '@/components/posts/previews/PreviewText.vue';
import PreviewVerticalCard from '@/components/posts/previews/PreviewVerticalCard.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import { filled } from '@/lib/helpers';
import { isVideo } from '@/lib/mediaType';
import { toNullableText } from '@/lib/utils';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';

const props = defineProps<PreviewProps>();

const videos = computed(() => props.media.filter((item) => isVideo(item)));

const description = computed((): string => {
    const custom = toNullableText(props.meta?.description);
    if (filled(custom)) {
        return custom;
    }
    return props.content;
});
const isLongForm = computed(
    () => props.contentType === ContentType.YouTubeVideo,
);
const title = computed((): string => {
    const custom = toNullableText(props.meta?.title);
    if (filled(custom)) {
        return custom;
    }
    return props.content;
});
const thumbnail = computed(() => props.meta?.youtube_thumbnail as MediaItem | undefined);
</script>

<template>
    <article
        v-if="isLongForm"
        class="space-y-3 p-4"
        data-testid="youtube-video-preview"
    >
        <div class="aspect-video overflow-hidden rounded-lg bg-black">
            <img v-if="thumbnail" :src="thumbnail.url" alt="" class="size-full object-contain" />
            <PostMediaPreview
                v-else
                :media="videos"
                :placeholder-icon="IconBrandYoutubeFilled"
                media-class="size-full object-contain"
            />
        </div>
        <p class="text-lg font-semibold">{{ title }}</p>
        <div class="flex items-center gap-2">
            <PreviewAvatar :account="socialAccount" class="size-8 text-sm" />
            <span>{{ socialAccount.display_label }}</span>
        </div>
        <p
            class="line-clamp-4 text-sm whitespace-pre-wrap text-muted-foreground"
        >
            {{ description }}
        </p>
    </article>
    <PreviewVerticalCard
        v-else
        data-testid="youtube-preview"
        :media="videos"
        :actions="[
            { icon: IconThumbUp, labelKey: 'posts.composer.preview.like' },
            { icon: IconThumbDown, labelKey: 'posts.composer.preview.dislike' },
            { icon: IconMessage2, labelKey: 'posts.composer.preview.comment' },
            { icon: IconShare3, labelKey: 'posts.composer.preview.share' },
        ]"
    >
        <template #placeholder>
            <IconBrandYoutubeFilled class="size-14 text-white" />
        </template>
        <template #footer>
            <div class="flex min-w-0 items-center gap-2">
                <PreviewAvatar
                    :account="socialAccount"
                    class="size-8 text-sm"
                />
                <span class="truncate font-semibold"
                    >@{{ socialAccount.handle_label }}</span
                >
                <span
                    class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-black"
                    data-testid="youtube-preview-subscribe"
                    >{{ $t('posts.composer.preview.subscribe') }}</span
                >
            </div>
            <p v-if="content" class="line-clamp-2">
                <PreviewText :text="content" overlay />
            </p>
            <details v-if="description" class="text-xs">
                <summary class="cursor-pointer">
                    {{ $t('posts.form.youtube.description') }}
                </summary>
                <p
                    class="mt-1 max-h-32 overflow-y-auto break-words whitespace-pre-wrap"
                    data-testid="youtube-preview-description"
                >
                    {{ description }}
                </p>
            </details>
        </template>
        <template #corner>
            <PreviewAvatar
                :account="socialAccount"
                square
                class="size-8 text-sm"
            />
        </template>
    </PreviewVerticalCard>
</template>
