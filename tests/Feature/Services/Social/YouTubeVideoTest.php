<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Services\Social\YouTubePublisher;
use Google\Client;
use Illuminate\Support\Facades\Http;

test('regular youtube videos accept longer media while shorts retain their duration limit', function () {
    $media = [['id' => 'video', 'type' => 'video', 'mime_type' => 'video/mp4', 'meta' => ['duration' => 600]]];

    expect(ContentTypeCompatibleWithMedia::errorsFor([['key' => 'content_type', 'content_type' => 'youtube_video']], $media))->toBeEmpty();
    expect(ContentTypeCompatibleWithMedia::errorsFor([['key' => 'content_type', 'content_type' => 'youtube_short']], $media))->toHaveKey('content_type');
    expect(ContentType::YouTubeVideo->maxVideoBytes())->toBe(1024 * 1024 * 1024);
    expect(ContentType::defaultFor(Platform::YouTube))->toBe(ContentType::YouTubeShort);
    expect(ContentType::fromPublication(Platform::YouTube, PublicationContentType::Video))->toBe(ContentType::YouTubeVideo);
});

test('regular youtube upload preserves its id and returns a watch url without uploading again', function () {
    $account = SocialAccount::factory()->youtube()->create(['token_expires_at' => now()->addDay()]);
    $post = Post::factory()->forAccount($account, ContentType::YouTubeVideo)->create([
        'content' => 'Video title',
        'media' => [['id' => 'video', 'type' => 'video', 'url' => 'https://example.com/long.mp4', 'mime_type' => 'video/mp4']],
        'meta' => ['title' => 'Custom title', 'description' => 'Description', 'privacy_status' => 'unlisted'],
    ]);
    Http::fake([
        'https://example.com/long.mp4' => Http::response(str_repeat('x', 2048)),
        'https://youtube.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.example.test/long']),
        'https://upload.example.test/long' => Http::response(['id' => 'long-video-id']),
    ]);
    $client = new Client;
    $client->setHttpClient(Http::buildClient());
    $this->instance(Client::class, $client);

    $result = app(YouTubePublisher::class)->publish($post);

    expect($result)->toBe(['id' => 'long-video-id', 'url' => 'https://www.youtube.com/watch?v=long-video-id']);
    expect($post->fresh()->platform_post_id)->toBe('long-video-id');
    Http::assertSentCount(3);
    Http::fake();

    expect(app(YouTubePublisher::class)->publish($post->fresh()))->toBe($result);
    Http::assertNothingSent();
});
