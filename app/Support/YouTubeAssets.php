<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Media\Type;
use App\Models\Post;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class YouTubeAssets
{
    public static function references(?array $meta): array
    {
        $items = [];

        if (is_array(data_get($meta, 'youtube_thumbnail'))) {
            $items['youtube_thumbnail'] = $meta['youtube_thumbnail'];
        }

        foreach (data_get($meta, 'youtube_captions', []) ?? [] as $index => $track) {
            if (is_array(data_get($track, 'media'))) {
                $items["youtube_captions.{$index}.media"] = $track['media'];
            }
        }

        return $items;
    }

    public static function validate(array $item, string $path, string $errorKey): void
    {
        $type = Type::classify(data_get($item, 'mime_type'), data_get($item, 'path'));

        if ($path === 'youtube_thumbnail') {
            if ($type !== Type::Image || ! in_array(data_get($item, 'mime_type'), ['image/jpeg', 'image/png'], true) || (int) data_get($item, 'size') > 2 * 1024 * 1024) {
                throw ValidationException::withMessages([$errorKey => __('posts.form.youtube.thumbnail_invalid')]);
            }

            return;
        }

        if ($type !== Type::Subtitle || (int) data_get($item, 'size') > TimedSubtitles::MAX_BYTES) {
            throw ValidationException::withMessages([$errorKey => __('posts.form.youtube.subtitle_invalid')]);
        }

        TimedSubtitles::assertValid((string) Storage::get($item['path']), (string) data_get($item, 'original_filename', $item['path']), $errorKey);
    }

    public static function validateOwned(Post $post, ?string $mediaId = null): void
    {
        foreach (self::references($post->meta) as $path => $item) {
            if ($mediaId !== null && data_get($item, 'id') !== $mediaId) {
                continue;
            }

            $row = $post->ownedMedia()->whereKey(data_get($item, 'id'))->first();

            if ($row === null) {
                throw ValidationException::withMessages(["meta.{$path}" => __('posts.errors.media_expired')]);
            }

            self::validate([
                'path' => $row->path, 'mime_type' => $row->mime_type, 'size' => $row->size, 'original_filename' => $row->original_filename,
            ], $path, "meta.{$path}");
        }
    }
}
