<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Support\YouTubeAssets;
use Google\Client;
use Google\Service\Exception as GoogleException;
use Google\Service\YouTube;
use Google\Service\YouTube\Caption;
use Google\Service\YouTube\CaptionSnippet;
use Google\Service\YouTube\PlaylistItem;
use Google\Service\YouTube\PlaylistItemSnippet;
use Google\Service\YouTube\ResourceId;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class YouTubeVideoExtras
{
    public function pending(Post $post): bool
    {
        if ($post->content_type !== ContentType::YouTubeVideo || blank($post->platform_post_id)) {
            return false;
        }

        foreach ($this->operations($post) as $key => $operation) {
            if (data_get($post->meta, "youtube_operations.{$key}.status") !== 'completed') {
                return true;
            }
        }

        return false;
    }

    public function process(Post $post, bool $retryFailed = false): bool
    {
        $retry = false;

        foreach ($this->operations($post) as $key => $operation) {
            if (data_get($post->meta, "youtube_operations.{$key}.status") === 'completed') {
                continue;
            }

            if (! $retryFailed && data_get($post->meta, "youtube_operations.{$key}.status") === 'failed') {
                continue;
            }

            $state = ['kind' => $operation['kind'], 'label' => $operation['label'], 'status' => 'pending', 'error' => null];

            try {
                if (isset($operation['media'])) {
                    YouTubeAssets::validateOwned($post, $operation['media']['id']);
                }
                $account = $post->socialAccount;

                if ($account->needsProactiveTokenRefresh()) {
                    app(ConnectionVerifier::class)->refreshToken($account);
                }

                $state = [...$state, ...$this->performWithRefresh($post, $operation, $retryFailed)];
                $retry = $retry || $state['status'] === 'pending';
            } catch (Throwable $exception) {
                Log::warning('YouTube optional upload failed', ['post_id' => $post->id, 'operation' => $operation['kind'], 'exception' => $exception::class]);
                $retryable = $exception instanceof GoogleException && in_array($exception->getCode(), [429, 500, 502, 503, 504], true);
                $retryable = $retryable || $exception instanceof ConnectException;

                if ($exception instanceof GoogleException && $exception->getCode() === 404 && $operation['kind'] !== 'playlist') {
                    $retryable = true;
                }
                $state['status'] = $retryable ? 'pending' : 'failed';
                $state['error'] = __('posts.form.youtube.operation_failed');

                if ($exception instanceof GoogleException) {
                    $reason = data_get($exception->getErrors(), '0.reason');

                    if (is_string($reason) && preg_match('/\A[a-zA-Z0-9_]+\z/', $reason)) {
                        $state['error'] .= " ({$reason})";
                    }
                }

                $retry = $retry || $retryable;
            }

            $meta = $post->meta ?? [];
            $meta['youtube_operations'][$key] = $state;
            $post->update(['meta' => $meta]);
        }

        return $retry;
    }

    public function operations(Post $post): array
    {
        if ($post->content_type !== ContentType::YouTubeVideo) {
            return [];
        }

        $operations = [];
        $thumbnail = data_get($post->meta, 'youtube_thumbnail');

        if (is_array($thumbnail)) {
            $operations['thumbnail'] = ['kind' => 'thumbnail', 'label' => data_get($thumbnail, 'original_filename', ''), 'media' => $thumbnail];
        }

        foreach (data_get($post->meta, 'youtube_captions', []) ?? [] as $track) {
            $name = trim((string) data_get($track, 'name'));

            if ($name === '') {
                $name = 'TryPost-'.data_get($track, 'media.id');
            }

            $key = 'caption_'.sha1(data_get($track, 'media.id').'|'.$track['language'].'|'.$name);
            $operations[$key] = ['kind' => 'caption', 'label' => $track['language'], 'name' => $name, ...$track];
            $operations[$key]['name'] = $name;
        }

        foreach (data_get($post->meta, 'youtube_playlists', []) ?? [] as $playlistId) {
            $operations['playlist_'.$playlistId] = ['kind' => 'playlist', 'label' => $playlistId, 'id' => $playlistId];
        }

        return $operations;
    }

    public function markUnfinishedFailed(Post $post): void
    {
        $meta = $post->meta ?? [];

        foreach ($meta['youtube_operations'] ?? [] as $key => $state) {
            if ($state['status'] === 'pending') {
                $meta['youtube_operations'][$key] = [...$state, 'status' => 'failed', 'error' => __('posts.form.youtube.operation_failed')];
            }
        }

        $post->update(['meta' => $meta]);
    }

    private function client(Post $post): YouTube
    {
        $client = app(Client::class);
        $client->setDefer(false);
        $client->setConfig('retry', ['retries' => 0]);
        $client->setAccessToken(['access_token' => $post->socialAccount->access_token]);

        return new YouTube($client);
    }

    private function performWithRefresh(Post $post, array $operation, bool $retryFailed): array
    {
        try {
            return $this->perform($this->client($post), $post, $operation, $retryFailed);
        } catch (GoogleException $exception) {
            if ($exception->getCode() !== 401) {
                throw $exception;
            }

            app(ConnectionVerifier::class)->refreshToken($post->socialAccount);

            return $this->perform($this->client($post), $post, $operation, $retryFailed);
        }
    }

    private function perform(YouTube $youtube, Post $post, array $operation, bool $retryFailed): array
    {
        $videoId = $post->platform_post_id;

        if ($operation['kind'] === 'thumbnail') {
            $row = $post->ownedMedia()->findOrFail($operation['media']['id']);
            $youtube->thumbnails->set($videoId, ['data' => Storage::get($row->path), 'mimeType' => $row->mime_type, 'uploadType' => 'media']);

            return ['status' => 'completed'];
        }

        if ($operation['kind'] === 'caption') {
            $tracks = $youtube->captions->listCaptions('snippet', ['videoId' => $videoId]);

            foreach ($tracks->getItems() as $track) {
                $snippet = $track->getSnippet();

                if ($snippet->getLanguage() !== $operation['language'] || $snippet->getName() !== $operation['name']) {
                    continue;
                }

                if ($snippet->getStatus() === 'failed') {
                    if ($retryFailed) {
                        $youtube->captions->delete($track->getId());

                        break;
                    }

                    return ['status' => 'failed', 'id' => $track->getId(), 'error' => __('posts.form.youtube.caption_processing_failed')];
                }

                if ($snippet->getStatus() === 'syncing') {
                    return ['status' => 'pending', 'id' => $track->getId()];
                }

                return ['status' => 'completed', 'id' => $track->getId()];
            }

            $row = $post->ownedMedia()->findOrFail($operation['media']['id']);
            $snippet = new CaptionSnippet;
            $snippet->setVideoId($videoId);
            $snippet->setLanguage($operation['language']);
            $snippet->setName($operation['name']);
            $snippet->setIsDraft(false);
            $caption = new Caption;
            $caption->setSnippet($snippet);
            $inserted = $youtube->captions->insert('snippet', $caption, ['data' => Storage::get($row->path), 'mimeType' => 'application/octet-stream', 'uploadType' => 'multipart']);

            return ['status' => 'pending', 'id' => $inserted->getId()];
        }

        if ($operation['kind'] === 'playlist') {
            $playlistId = $operation['id'];
            $playlists = $youtube->playlists->listPlaylists('snippet', ['id' => $playlistId]);
            $playlist = $playlists->getItems()[0] ?? null;

            if ($playlist === null || $playlist->getSnippet()->getChannelId() !== $post->socialAccount->platform_user_id) {
                throw new RuntimeException('Playlist does not belong to the connected channel.');
            }

            $items = $youtube->playlistItems->listPlaylistItems('id', ['playlistId' => $playlistId, 'videoId' => $videoId, 'maxResults' => 1]);

            if ($items->getItems() !== []) {
                return ['status' => 'completed'];
            }

            $resource = new ResourceId;
            $resource->setKind('youtube#video');
            $resource->setVideoId($videoId);
            $snippet = new PlaylistItemSnippet;
            $snippet->setPlaylistId($playlistId);
            $snippet->setResourceId($resource);
            $item = new PlaylistItem;
            $item->setSnippet($snippet);
            $youtube->playlistItems->insert('snippet', $item);

            return ['status' => 'completed'];
        }

        throw new RuntimeException('Unknown YouTube operation.');
    }
}
