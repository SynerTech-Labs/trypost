<?php

declare(strict_types=1);

use App\Actions\Post\DuplicatePost;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\SyncTryPostPublication;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\ReconcileTikTokDraft;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

function tikTokDraftPost(array $attributes = [], array $scopes = ['video.upload']): Post
{
    $account = SocialAccount::factory()->tiktok()->create([
        'scopes' => $scopes,
        'username' => 'creator',
        'token_expires_at' => now()->addDay(),
    ]);

    return Post::factory()->forAccount($account, ContentType::TikTokVideo)->publishing()->create([
        'content' => 'Prepared caption #example',
        'meta' => ['posting_mode' => 'draft_upload'],
        'media' => [[
            'id' => 'draft-video',
            'type' => 'video',
            'path' => 'media/video.mp4',
            'url' => 'https://media.example.com/video.mp4?signature=private',
            'mime_type' => 'video/mp4',
        ]],
        ...$attributes,
    ]);
}

function fakeTikTokDraft(string $status, array $data = []): void
{
    $api = config('trypost.platforms.tiktok.api');
    Http::fake([
        $api.'/post/publish/inbox/video/init/' => Http::response(['data' => ['publish_id' => 'v_inbox_url~draft'], 'error' => ['code' => 'ok']]),
        $api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => $status, ...$data], 'error' => ['code' => 'ok']]),
    ]);
}

function reconcileTikTokDraft(Post $post): void
{
    app()->call([new ReconcileTikTokDraft($post), 'handle']);
}

test('draft delivery sends only the source video and never reports publication or analytics', function () {
    Queue::fake();
    Sleep::fake();
    $post = tikTokDraftPost();
    fakeTikTokDraft('SEND_TO_USER_INBOX');

    (new PublishToSocialPlatform($post))->handle();
    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh())->status->toBe(Status::Publishing)
        ->publish_status->toBe(PublishStatus::InboxDelivered)
        ->published_at->toBeNull()->platform_url->toBeNull()
        ->platform_post_id->toBe('v_inbox_url~draft')
        ->content->toBe('Prepared caption #example');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/inbox/video/init/')
        && $request->data() === ['source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => 'https://media.example.com/video.mp4?signature=private']]);
    Http::assertSentCount(2);
    Queue::assertNotPushed(SendNotification::class);
    Queue::assertNotPushed(SyncTryPostPublication::class);
});

test('draft uploads without the upload grant fail with reconnect instructions before contacting TikTok', function () {
    Queue::fake();
    $post = tikTokDraftPost(scopes: ['video.publish']);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh())->publish_status->toBe(PublishStatus::Failed)
        ->error_message->toBe(__('posts.form.tiktok.draft_scope_required'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tiktokapis'));
});

test('processing transfers resume the checkpoint without another inbox initialization', function (string $status) {
    Queue::fake();
    Sleep::fake();
    $post = tikTokDraftPost();
    $api = config('trypost.platforms.tiktok.api');
    Http::fake([
        $api.'/post/publish/inbox/video/init/' => Http::response(['data' => ['publish_id' => 'v_inbox_url~draft']]),
        $api.'/post/publish/status/fetch/' => Http::sequence()
            ->push(['data' => ['status' => $status]])
            ->push(['data' => ['status' => 'SEND_TO_USER_INBOX']]),
    ]);

    (new PublishToSocialPlatform($post))->handle();
    expect($post->fresh()->publish_status)->toBe(PublishStatus::Retrying);
    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::InboxDelivered);
    Http::assertSentCount(3);
    Queue::assertPushed(PublishToSocialPlatform::class);
})->with(['PROCESSING_DOWNLOAD', 'PROCESSING_UPLOAD']);

test('manual completion without public ids has no public link and finalizes only once', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');
    fakeTikTokDraft('PUBLISH_COMPLETE');

    reconcileTikTokDraft($post);
    $notifications = Queue::pushed(SendNotification::class)->count();
    reconcileTikTokDraft($post);

    expect($post->fresh())->status->toBe(Status::Published)
        ->publish_status->toBe(PublishStatus::Published)
        ->platform_url->toBeNull()->platform_post_id->toBe('v_inbox_url~draft');
    expect($post->fresh()->published_at)->not->toBeNull();
    expect(Queue::pushed(SendNotification::class)->count())->toBe($notifications);
    Http::assertSentCount(2);
});

test('all public ids are retained and only a real public id becomes the post link', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');
    fakeTikTokDraft('PUBLISH_COMPLETE', ['publicaly_available_post_id' => ['7694860629638940686', 7694860629638940687]]);

    reconcileTikTokDraft($post);

    expect($post->fresh())->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@creator/video/7694860629638940686');
    expect($post->fresh()->meta['tiktok_public_post_ids'])->toBe(['7694860629638940686', '7694860629638940687']);
});

test('a failed transfer preserves its checkpoint and actionable TikTok reason without a link', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');
    fakeTikTokDraft('FAILED', ['fail_reason' => 'duration_check_failed']);

    reconcileTikTokDraft($post);

    expect($post->fresh())->status->toBe(Status::Failed)->platform_url->toBeNull()
        ->error_message->toBe('Video duration is not within allowed limits.');
    expect($post->fresh()->error_context)->toMatchArray(['tiktok_publish_id' => 'v_inbox_url~draft', 'tiktok_status' => 'FAILED']);
});

test('an unavailable status endpoint does not erase inbox delivery or initialize again', function (int $httpStatus, string $code) {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');
    Http::fake([config('trypost.platforms.tiktok.api').'/post/publish/status/fetch/' => Http::response(['error' => ['code' => $code]], $httpStatus)]);

    reconcileTikTokDraft($post);

    expect($post->fresh())->publish_status->toBe(PublishStatus::InboxDelivered)->published_at->toBeNull();
    Http::assertSentCount(1);
})->with([[500, 'internal_error'], [400, 'invalid_publish_id'], [401, 'scope_not_authorized']]);

test('draft reconciliation stops after thirty days without inventing publication or failure', function () {
    Queue::fake();
    $this->freezeTime();
    $post = tikTokDraftPost(['submitted_at' => now()->subDays(30), 'error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');

    reconcileTikTokDraft($post);
    $this->artisan('social:resolve-tiktok-video-ids')->assertSuccessful();

    expect($post->fresh())->publish_status->toBe(PublishStatus::InboxDelivered)->published_at->toBeNull();
    Queue::assertNotPushed(ReconcileTikTokDraft::class);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tiktokapis'));
});

test('the existing reconciliation sweep dispatches inbox drafts once they are due', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');

    $this->artisan('social:resolve-tiktok-video-ids')->assertSuccessful();

    Queue::assertPushed(ReconcileTikTokDraft::class, fn (ReconcileTikTokDraft $job): bool => $job->post->is($post));
});

test('an uncertain initialization response never triggers a duplicate inbox transfer on retry', function (string $outcome) {
    Queue::fake();
    $post = tikTokDraftPost();
    $response = match ($outcome) {
        'connection lost' => Http::failedConnection(),
        'server error' => Http::response(['error' => ['code' => 'internal_error']], 500),
        'missing id' => Http::response(['data' => [], 'error' => ['code' => 'ok']]),
    };
    Http::fake([config('trypost.platforms.tiktok.api').'/post/publish/inbox/video/init/' => $response]);

    (new PublishToSocialPlatform($post))->handle();
    $this->artisan('posts:retry', ['post' => $post->id])->expectsConfirmation('Queue a publish attempt for this post?', 'yes')->assertSuccessful();
    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->error_message)->toBe(__('posts.form.tiktok.draft_unconfirmed'));
    expect($post->fresh()->error_context['tiktok_draft_init_attempted'])->toBeTrue();
    Http::assertSentCount(1);
})->with(['connection lost', 'server error', 'missing id']);

test('a non HTTPS source fails before initialization', function () {
    Queue::fake();
    $post = tikTokDraftPost();
    $media = $post->media;
    $media[0]['url'] = 'http://media.example.com/video.mp4';
    $post->update(['media' => $media]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->error_message)->toBe(__('posts.form.tiktok.draft_source_required'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tiktokapis'));
});

test('draft mode bypasses privacy while a legacy direct post still requires it', function () {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, ['posting_mode' => 'draft_upload'], ContentType::TikTokVideo))->toBeNull();
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, [], ContentType::TikTokVideo))->toBe(['privacy_level', __('posts.form.tiktok.privacy_required')]);
});

test('photo drafts cannot bypass shared stored post validation', function () {
    $post = tikTokDraftPost(['content_type' => ContentType::TikTokPhoto]);

    expect(fn () => PostPlatformMetaRules::assertStoredPostPublishable($post))->toThrow(ValidationException::class, __('posts.form.tiktok.draft_video_required'));
});

test('an expired access token refreshes through the existing verifier before draft initialization', function () {
    Queue::fake();
    Sleep::fake();
    $post = tikTokDraftPost();
    $post->socialAccount->update(['token_expires_at' => now()->subMinute(), 'refresh_token' => 'refresh-token']);
    fakeTikTokDraft('SEND_TO_USER_INBOX');
    Http::fake([config('trypost.platforms.tiktok.api').'/oauth/token/' => Http::response([
        'access_token' => 'refreshed-token', 'refresh_token' => 'rotated-token', 'expires_in' => 86400,
    ])]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::InboxDelivered);
    expect($post->socialAccount->fresh()->refresh_token)->toBe('rotated-token');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/inbox/video/init/') && $request->hasHeader('Authorization', 'Bearer refreshed-token'));
});

test('a completed draft can resolve its public id later while retaining every public id', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');
    $api = config('trypost.platforms.tiktok.api');
    Http::fake([$api.'/post/publish/status/fetch/' => Http::sequence()
        ->push(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []]])
        ->push(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7694860629638940686', '7694860629638940687']]])]);

    reconcileTikTokDraft($post);
    expect($post->fresh()->platform_url)->toBeNull();
    reconcileTikTokDraft($post);

    expect($post->fresh())->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@creator/video/7694860629638940686');
    expect($post->fresh()->meta['tiktok_public_post_ids'])->toHaveCount(2);
    Http::assertSentCount(2);
});

test('duplicating a delivered draft keeps the selected mode and caption without its transfer identity', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => ['tiktok_publish_id' => 'v_inbox_url~draft']]);
    $post->markPublicationInboxDelivered('v_inbox_url~draft');
    $post->update(['media' => [], 'meta' => ['posting_mode' => 'draft_upload', 'tiktok_public_post_ids' => ['7694860629638940686']]]);

    $copy = DuplicatePost::execute($post, $post->user);

    expect($copy)->status->toBe(Status::Draft)->content->toBe('Prepared caption #example')
        ->error_context->toBeNull()->platform_post_id->toBeNull();
    expect($copy->meta)->toBe(['posting_mode' => 'draft_upload']);
});

test('a processing draft transfer reaches the one hour ceiling without another initialization', function () {
    Queue::fake();
    $post = tikTokDraftPost(['error_context' => [
        'tiktok_publish_id' => 'v_inbox_url~draft',
        'tiktok_draft_init_attempted' => true,
        'tiktok_init_at' => now()->subHour()->toIso8601String(),
    ]]);
    fakeTikTokDraft('PROCESSING_DOWNLOAD');

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh())->publish_status->toBe(PublishStatus::Failed)
        ->error_message->toBe(__('posts.form.tiktok.draft_unconfirmed'));
    Http::assertSentCount(1);
});
