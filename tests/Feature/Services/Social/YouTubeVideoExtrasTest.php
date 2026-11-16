<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Dto\MediaItem;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Media\Type;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\CompleteYouTubeVideo;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\YouTubeVideoExtras;
use Google\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function youtubeExtrasPost(): Post
{
    $account = SocialAccount::factory()->youtube()->create(['token_expires_at' => now()->addDay()]);

    return Post::factory()->forAccount($account, ContentType::YouTubeVideo)->create([
        'content' => 'Long-form video', 'status' => Status::Published,
        'publish_status' => PublishStatus::Published, 'platform_post_id' => 'video-123',
        'platform_url' => 'https://www.youtube.com/watch?v=video-123',
    ]);
}

function youtubeExtrasClient(): void
{
    $client = new Client;
    $client->setHttpClient(Http::buildClient());
    test()->instance(Client::class, $client);
}

function youtubeExtrasThumbnail(Post $post): array
{
    $media = Media::factory()->ownedByPost($post)->stored()->create(['size' => 1000]);

    return MediaItem::fromMedia($media)->toArray();
}

function youtubeExtrasCaption(Post $post, string $language, string $name): array
{
    $text = "1\n00:00:00,000 --> 00:00:02,000\nHello\n";
    $media = Media::factory()->ownedByPost($post)->create([
        'type' => Type::Subtitle, 'path' => "captions/{$language}.srt", 'original_filename' => "{$language}.srt",
        'mime_type' => 'application/x-subrip', 'size' => strlen($text),
    ]);
    Storage::put($media->path, $text);

    return ['media' => MediaItem::fromMedia($media)->toArray(), 'language' => $language, 'name' => $name];
}

test('thumbnail failure never fails the published video and retries only unfinished work', function () {
    Storage::fake();
    $post = youtubeExtrasPost();
    $post->update(['meta' => ['youtube_thumbnail' => youtubeExtrasThumbnail($post), 'youtube_playlists' => ['PL123']]]);
    Http::fake([
        '*thumbnails*' => Http::sequence()->push(['error' => ['errors' => [['reason' => 'backendError']]]], 503)->push(['items' => []]),
        '*playlists*' => Http::response(['items' => [['id' => 'PL123', 'snippet' => ['channelId' => $post->socialAccount->platform_user_id]]]]),
        '*playlistItems*' => fn (Request $request) => Http::response(['items' => [], 'id' => 'playlist-item']),
    ]);
    youtubeExtrasClient();
    $extras = app(YouTubeVideoExtras::class);

    expect($extras->process($post))->toBeTrue();
    expect(data_get($post->fresh()->meta, 'youtube_operations.thumbnail.status'))->toBe('pending');
    expect(data_get($post->meta, 'youtube_operations.playlist_PL123.status'))->toBe('completed');
    expect($post->fresh()->publish_status)->toBe(PublishStatus::Published);
    expect($post->platform_post_id)->toBe('video-123');

    expect($extras->process($post))->toBeFalse();
    expect($extras->pending($post))->toBeFalse();
    Http::assertSentCount(5);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/videos'));
});

test('multiple timed subtitle languages upload once and reconcile after processing', function () {
    Storage::fake();
    $post = youtubeExtrasPost();
    $post->update(['meta' => ['youtube_captions' => [youtubeExtrasCaption($post, 'en', 'English'), youtubeExtrasCaption($post, 'fr', 'French')]]]);
    $processingComplete = false;
    Http::fake([
        '*captions*' => function (Request $request) use (&$processingComplete) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'caption-id', 'snippet' => ['status' => 'syncing']]);
            }

            if ($processingComplete) {
                return Http::response(['items' => [
                    ['id' => 'en-id', 'snippet' => ['language' => 'en', 'name' => 'English', 'status' => 'serving']],
                    ['id' => 'fr-id', 'snippet' => ['language' => 'fr', 'name' => 'French', 'status' => 'serving']],
                ]]);
            }

            return Http::response(['items' => []]);
        },
    ]);
    youtubeExtrasClient();
    $extras = app(YouTubeVideoExtras::class);

    expect($extras->process($post))->toBeTrue();
    $processingComplete = true;
    expect($extras->process($post))->toBeFalse();
    expect($extras->pending($post))->toBeFalse();
    Http::assertSentCount(6);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->body(), base64_encode("1\n00:00:00,000 --> 00:00:02,000\nHello\n"))
        && ! str_contains($request->url(), 'sync='));
    expect(collect(data_get($post->meta, 'youtube_operations'))->pluck('id')->all())->toEqualCanonicalizing(['en-id', 'fr-id']);
});

test('playlist retries detect an existing video instead of inserting a duplicate', function () {
    $post = youtubeExtrasPost();
    $post->update(['meta' => ['youtube_playlists' => ['PL123']]]);
    Http::fake([
        '*playlists*' => Http::response(['items' => [['id' => 'PL123', 'snippet' => ['channelId' => $post->socialAccount->platform_user_id]]]]),
        '*playlistItems*' => Http::response(['items' => [['id' => 'existing-item']]]),
    ]);
    youtubeExtrasClient();

    expect(app(YouTubeVideoExtras::class)->process($post))->toBeFalse();

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('deleted and foreign playlists fail independently without changing video publication', function (bool $deleted) {
    $post = youtubeExtrasPost();
    $post->update(['meta' => ['youtube_playlists' => ['PL123']]]);
    Http::fake(['*playlists*' => $deleted
        ? Http::response(['error' => ['errors' => [['reason' => 'playlistNotFound']]]], 404)
        : Http::response(['items' => [['id' => 'PL123', 'snippet' => ['channelId' => 'another-channel']]]]),
    ]);
    youtubeExtrasClient();

    expect(app(YouTubeVideoExtras::class)->process($post))->toBeFalse();

    expect(data_get($post->meta, 'youtube_operations.playlist_PL123.status'))->toBe('failed');
    expect($post->fresh()->platform_post_id)->toBe('video-123');
    expect($post->publish_status)->toBe(PublishStatus::Published);
    Http::assertSentCount(1);
})->with([true, false]);

test('failed subtitle processing does not repeat its insert or block a thumbnail', function () {
    Storage::fake();
    $post = youtubeExtrasPost();
    $post->update(['meta' => [
        'youtube_thumbnail' => youtubeExtrasThumbnail($post),
        'youtube_captions' => [youtubeExtrasCaption($post, 'en', 'English')],
    ]]);
    Http::fake([
        '*thumbnails*' => Http::response(['items' => []]),
        '*captions*' => Http::response(['items' => [['id' => 'failed-track', 'snippet' => ['language' => 'en', 'name' => 'English', 'status' => 'failed']]]]),
    ]);
    youtubeExtrasClient();

    expect(app(YouTubeVideoExtras::class)->process($post))->toBeFalse();

    expect(data_get($post->meta, 'youtube_operations.thumbnail.status'))->toBe('completed');
    expect(collect($post->meta['youtube_operations'])->where('kind', 'caption')->sole()['status'])->toBe('failed');
    Http::assertSentCount(2);
});

test('a recovered uploaded identity is finalized and queues extras without another upload', function () {
    Queue::fake([CompleteYouTubeVideo::class]);
    $post = youtubeExtrasPost();
    $post->writePublication(['publish_status' => PublishStatus::Publishing]);
    $post->update(['status' => Status::Publishing, 'meta' => ['youtube_playlists' => ['PL123']]]);
    Http::fake();

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::Published);
    expect($post->platform_post_id)->toBe('video-123');
    Queue::assertPushed(CompleteYouTubeVideo::class);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/upload/youtube/v3/videos'));
});

test('regular videos reuse the existing analytics publication identity and video type', function () {
    $post = youtubeExtrasPost();

    $publication = app(SyncTryPostPublication::class)->handle($post);

    expect($publication->content_type)->toBe(PublicationContentType::Video);
    expect($publication->remote_id)->toBe('video-123');
});

test('optional upload refreshes a rejected access token without repeating the video upload', function () {
    Storage::fake();
    $post = youtubeExtrasPost();
    $post->socialAccount->update(['refresh_token' => 'refresh-token']);
    $post->update(['meta' => ['youtube_thumbnail' => youtubeExtrasThumbnail($post)]]);
    Http::fake([
        '*thumbnails*' => Http::sequence()->push(['error' => ['errors' => [['reason' => 'authError']]]], 401)->push(['items' => []]),
        config('trypost.platforms.youtube.oauth_api').'/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600]),
    ]);
    youtubeExtrasClient();

    expect(app(YouTubeVideoExtras::class)->process($post))->toBeFalse();

    expect(data_get($post->meta, 'youtube_operations.thumbnail.status'))->toBe('completed');
    expect($post->socialAccount->fresh()->access_token)->toBe('new-token');
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/videos'));
});

test('manual retry can replace a failed caption while permanent failures are not retried automatically', function () {
    Storage::fake();
    $post = youtubeExtrasPost();
    $post->update(['meta' => ['youtube_captions' => [youtubeExtrasCaption($post, 'en', 'English')]]]);
    Http::fake(['*captions*' => Http::sequence()
        ->push(['items' => [['id' => 'failed-track', 'snippet' => ['language' => 'en', 'name' => 'English', 'status' => 'failed']]]])
        ->push(['items' => [['id' => 'failed-track', 'snippet' => ['language' => 'en', 'name' => 'English', 'status' => 'failed']]]])
        ->push('', 204)->push(['id' => 'new-track', 'snippet' => ['status' => 'syncing']]),
    ]);
    youtubeExtrasClient();
    $extras = app(YouTubeVideoExtras::class);

    expect($extras->process($post))->toBeFalse();
    expect($extras->process($post))->toBeFalse();
    Http::assertSentCount(1);
    expect($extras->process($post, retryFailed: true))->toBeTrue();
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'id=failed-track'));
    expect($post->fresh()->platform_post_id)->toBe('video-123');
});

test('an exhausted optional job records unfinished operations without failing the video', function () {
    $post = youtubeExtrasPost();
    $post->update(['meta' => ['youtube_playlists' => ['PL123']]]);

    (new CompleteYouTubeVideo($post))->failed(new RuntimeException('Worker stopped'));

    expect(data_get($post->fresh()->meta, 'youtube_operations.playlist_PL123.status'))->toBe('failed');
    expect($post->platform_post_id)->toBe('video-123');
    expect($post->publish_status)->toBe(PublishStatus::Published);
});
