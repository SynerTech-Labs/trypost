<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useMetaErrors } from '@/composables/useMetaErrors';
import { filled, isString } from '@/lib/helpers';
import { storeChunked } from '@/routes/app/media';
import { account as composerAccount } from '@/routes/app/posts/composer';
import type { MediaItem } from '@/types/media';
import { ChunkedUploadError, uploadChunked } from '@/utils/chunkedUpload';

interface Track {
    media: MediaItem;
    language: string;
    name?: string | null;
}

const props = defineProps<{
    accountId: string;
    platformIndex: number;
    meta: Record<string, unknown>;
    disabled: boolean;
}>();
const metaError = useMetaErrors(() => props.platformIndex);
const thumbnailError = metaError('youtube_thumbnail.0');
const captionsError = metaError('youtube_captions');
const emit = defineEmits<{
    'update:meta': [value: Record<string, unknown>];
    busy: [value: boolean];
}>();
const update = (patch: Record<string, unknown>): void => {
    emit('update:meta', { ...props.meta, ...patch });
};
const thumbnail = computed(
    () => props.meta.youtube_thumbnail as MediaItem | null,
);
const tracks = computed((): Track[] => {
    if (Array.isArray(props.meta.youtube_captions)) {
        return props.meta.youtube_captions as Track[];
    }
    return [];
});
const selectedIds = computed((): string[] => {
    if (Array.isArray(props.meta.youtube_playlists)) {
        return props.meta.youtube_playlists as string[];
    }
    return [];
});
const uploading = ref(false);
const error = ref('');
const errorKey = ref('');
const disabled = computed(() => {
    if (props.disabled) {
        return true;
    }
    return uploading.value;
});
const http = useHttp<
    Record<string, never>,
    {
        youtubePlaylists: {
            playlists: { id: string; title: string }[];
            truncated: boolean;
        } | null;
    }
>({});
const available = ref<{ id: string; title: string }[]>([]);
const playlistsFailed = ref(false);
const truncated = ref(false);
const playlistChoices = computed(() =>
    available.value.map((playlist) => ({
        ...playlist,
        selected: selectedIds.value.includes(playlist.id),
    })),
);
const loadPlaylists = async (): Promise<void> => {
    playlistsFailed.value = false;
    try {
        const result = await http.get(composerAccount.url(props.accountId));
        if (!filled(result?.youtubePlaylists)) {
            playlistsFailed.value = true;
            return;
        }
        available.value = result.youtubePlaylists.playlists;
        truncated.value = result.youtubePlaylists.truncated;
    } catch {
        playlistsFailed.value = true;
    }
};
onMounted(loadPlaylists);
onUnmounted(() => emit('busy', false));
const togglePlaylist = (id: string): void => {
    if (selectedIds.value.includes(id)) {
        update({
            youtube_playlists: selectedIds.value.filter(
                (value) => value !== id,
            ),
        });
        return;
    }
    update({ youtube_playlists: [...selectedIds.value, id] });
};
const removeThumbnail = (): void => update({ youtube_thumbnail: null });
const trackName = (track: Track): string => {
    if (isString(track.name)) {
        return track.name;
    }
    return '';
};
const removeTrack = (index: number): void =>
    update({
        youtube_captions: tracks.value.filter(
            (_, position) => position !== index,
        ),
    });
const changeTrack = (
    index: number,
    field: 'language' | 'name',
    event: Event,
): void => {
    const value = (event.target as HTMLInputElement).value;
    update({
        youtube_captions: tracks.value.map((track, position) => {
            if (position === index) {
                return { ...track, [field]: value };
            }
            return track;
        }),
    });
};
const upload = async (
    event: Event,
    kind: 'thumbnail' | 'caption',
): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    error.value = '';
    errorKey.value = '';
    uploading.value = true;
    emit('busy', true);
    const added: Track[] = [];
    try {
        for (const file of files) {
            if (
                kind === 'thumbnail' &&
                !['image/jpeg', 'image/png'].includes(file.type)
            ) {
                errorKey.value = 'posts.form.youtube.thumbnail_invalid';
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                errorKey.value = 'posts.form.youtube.auxiliary_size';
                return;
            }
            const item = await uploadChunked({ file, url: storeChunked.url() });
            if (kind === 'thumbnail') {
                update({ youtube_thumbnail: item });
                continue;
            }
            added.push({ media: item, language: 'en', name: '' });
        }
    } catch (exception) {
        if (
            exception instanceof ChunkedUploadError &&
            isString(exception.serverMessage)
        ) {
            error.value = exception.serverMessage;
        } else {
            errorKey.value = 'posts.form.youtube.operation_failed';
        }
    } finally {
        if (filled(added)) {
            update({ youtube_captions: [...tracks.value, ...added] });
        }
        uploading.value = false;
        emit('busy', false);
        input.value = '';
    }
};
const uploadThumbnail = (event: Event): Promise<void> =>
    upload(event, 'thumbnail');
const uploadCaptions = (event: Event): Promise<void> =>
    upload(event, 'caption');
const thumbnailFilename = computed(() => {
    if (filled(thumbnail.value?.original_filename)) {
        return thumbnail.value.original_filename;
    }
    return '';
});
</script>

<template>
    <div class="contents">
        <SettingsRow :label="$t('posts.form.youtube.thumbnail')" align-top>
            <div class="space-y-2">
                <InputError :message="thumbnailError" />
                <input
                    type="file"
                    accept="image/jpeg,image/png"
                    :disabled="disabled"
                    :aria-label="$t('posts.form.youtube.thumbnail')"
                    data-testid="youtube-thumbnail"
                    @change="uploadThumbnail"
                />
                <div v-if="thumbnail" class="flex items-center gap-2">
                    <img
                        :src="thumbnail.url"
                        alt=""
                        class="aspect-video w-24 rounded object-cover"
                    />
                    <span class="truncate text-sm">{{
                        thumbnailFilename
                    }}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled"
                        @click="removeThumbnail"
                        >{{ $t('posts.form.youtube.remove') }}</Button
                    >
                </div>
            </div>
        </SettingsRow>
        <SettingsRow :label="$t('posts.form.youtube.subtitles')" align-top>
            <div class="space-y-3">
                <InputError :message="captionsError" />
                <input
                    type="file"
                    accept=".srt,.vtt"
                    multiple
                    :disabled="disabled"
                    :aria-label="$t('posts.form.youtube.subtitles')"
                    data-testid="youtube-subtitles"
                    @change="uploadCaptions"
                />
                <div
                    v-for="(track, index) in tracks"
                    :key="track.media.id"
                    class="space-y-2 rounded border p-2"
                >
                    <p class="truncate text-sm">
                        {{ track.media.original_filename }}
                    </p>
                    <Input
                        :model-value="track.language"
                        :disabled="disabled"
                        :aria-label="$t('posts.form.youtube.language')"
                        :placeholder="$t('posts.form.youtube.language')"
                        data-testid="youtube-subtitle-language"
                        @input="changeTrack(index, 'language', $event)"
                    />
                    <Input
                        :model-value="trackName(track)"
                        :disabled="disabled"
                        :aria-label="$t('posts.form.youtube.track_name')"
                        :placeholder="$t('posts.form.youtube.track_name')"
                        @input="changeTrack(index, 'name', $event)"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled"
                        @click="removeTrack(index)"
                        >{{ $t('posts.form.youtube.remove') }}</Button
                    >
                </div>
            </div>
        </SettingsRow>
        <SettingsRow :label="$t('posts.form.youtube.playlists')" align-top>
            <div class="space-y-2">
                <p v-if="http.processing" class="text-sm" role="status">
                    {{ $t('common.loading') }}
                </p>
                <div v-if="playlistsFailed" class="space-y-2">
                    <p class="text-sm text-destructive">
                        {{ $t('posts.form.youtube.playlists_failed') }}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :loading="http.processing"
                        @click="loadPlaylists"
                        >{{ $t('posts.form.youtube.retry') }}</Button
                    >
                </div>
                <label
                    v-for="playlist in playlistChoices"
                    :key="playlist.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="playlist.selected"
                        :data-testid="`youtube-playlist-${playlist.id}`"
                        :disabled="disabled"
                        @update:model-value="togglePlaylist(playlist.id)"
                    />
                    {{ playlist.title }}
                </label>
                <p v-if="truncated" class="text-sm text-muted-foreground">
                    {{ $t('posts.form.youtube.playlist_truncated') }}
                </p>
            </div>
        </SettingsRow>
        <SettingsRow>
            <div class="space-y-1">
                <p v-if="uploading" role="status" class="text-sm">
                    {{ $t('common.loading') }}
                </p>
                <InputError :message="error" />
                <p v-if="errorKey" class="text-sm text-destructive">
                    {{ $t(errorKey) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t('posts.form.youtube.classification') }}
                </p>
            </div>
        </SettingsRow>
    </div>
</template>
