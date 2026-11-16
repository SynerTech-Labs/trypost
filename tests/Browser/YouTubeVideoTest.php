<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\CompleteYouTubeVideo;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    Storage::delete(Media::query()->pluck('path')->all());
});

test('youtube video composer saves auxiliary uploads and shows its planner format', function (string $locale, int $width) {
    $user = User::factory()->create(['locale' => $locale]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id, 'token_expires_at' => now()->addDay()]);
    $asset = Media::factory()->video()->temporaryUpload($workspace)->create(['meta' => ['duration' => 30]]);
    Storage::put($asset->path, (string) file_get_contents(base_path('tests/fixtures/sample.mp4')));
    $post = Post::factory()->forAccount($account, ContentType::YouTubeShort)->create([
        'user_id' => $user->id, 'content' => 'A complete video', 'status' => Status::Draft,
        'media' => [MediaItem::fromMedia($asset)->toArray()], 'meta' => ['title' => 'Video title'],
    ]);
    Http::fake([config('trypost.platforms.youtube.data_api').'/playlists*' => Http::response([
        'items' => [['id' => 'PL123', 'snippet' => ['title' => 'Product videos', 'channelId' => $account->platform_user_id]]],
    ])]);
    $this->actingAs($user);
    $page = visit(route('app.posts.edit', $post))->resize($width, 1000);
    waitFor($page, "@composer-type-{$account->id}-youtube_video");
    $page->click("@composer-type-{$account->id}-youtube_video");
    waitFor($page, '@youtube-thumbnail');
    waitFor($page, '@youtube-playlist-PL123');
    $page->click('@youtube-playlist-PL123')->fill('@youtube-title', 'Long-form title');
    $thumbnail = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (() => {
            const input = document.querySelector('[data-testid="youtube-thumbnail"]');
            const transfer = new DataTransfer();
            const bytes = Uint8Array.from(atob('{$thumbnail}'), character => character.charCodeAt(0));
            transfer.items.add(new File([bytes], 'cover.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    waitForCondition($page, '!document.querySelector(\'[data-testid="youtube-thumbnail"]\').disabled');
    $page->script(<<<'JS'
        (() => {
            const input = document.querySelector('[data-testid="youtube-subtitles"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File(['1\n00:00:00,000 --> 00:00:02,000\nHello'], 'en.srt', { type: 'text/plain' }));
            transfer.items.add(new File(['WEBVTT\n\n00:00.000 --> 00:02.000\nBonjour'], 'fr.vtt', { type: 'text/vtt' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    waitForCount($page, '@youtube-subtitle-language', 2);
    $page->script(<<<'JS'
        (() => {
            const input = document.querySelectorAll('[data-testid="youtube-subtitle-language"]')[1];
            input.value = 'fr';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        })();
    JS);
    $page->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    if ($width < 1024) {
        $page->click('@composer-view-preview');
    }

    waitFor($page, '@youtube-video-preview');
    $page->assertSeeIn('@youtube-video-preview', 'Long-form title');

    if ($width < 1024) {
        $page->click('@composer-view-edit');
    }

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');
    $post->refresh();
    expect($post->content_type)->toBe(ContentType::YouTubeVideo);
    expect($post->media)->toHaveCount(1);
    expect($post->ownedMedia()->count())->toBe(4);
    expect(data_get($post->meta, 'youtube_captions.1.language'))->toBe('fr');
    expect(data_get($post->meta, 'youtube_playlists'))->toBe(['PL123']);
    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-content-type-{$post->id}");
    $page->assertSeeIn("@post-content-type-{$post->id}", __('posts.content_types.youtube_video.label', [], $locale))
        ->assertNoJavaScriptErrors();
    $post->update(['status' => Status::Scheduled, 'scheduled_at' => now()->addHour()]);
    $page = visit(route('app.calendar', ['view' => 'month']));
    waitFor($page, "@calendar-post-format-{$post->id}");
    $page->assertSeeIn("@calendar-post-format-{$post->id}", __('posts.content_types.youtube_video.label', [], $locale))
        ->assertNoJavaScriptErrors();
})->with([['en', 1440], ['de', 375]]);

test('published video details show optional failures and retry without republishing', function () {
    Queue::fake([CompleteYouTubeVideo::class, BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account, ContentType::YouTubeVideo)->create([
        'user_id' => $user->id, 'content' => 'Uploaded video', 'status' => Status::Published,
        'publish_status' => PublishStatus::Published, 'published_at' => now(), 'platform_post_id' => 'saved-video',
        'meta' => ['youtube_playlists' => ['PL123'], 'youtube_operations' => ['playlist_PL123' => [
            'kind' => 'playlist', 'label' => 'PL123', 'status' => 'failed', 'error' => 'playlistNotFound',
        ]]],
    ]);
    $this->actingAs($user);
    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitFor($page, "@post-open-{$post->id}");
    $page->click("@post-open-{$post->id}");
    waitFor($page, '@youtube-video-status');
    $page->assertSeeIn('@youtube-video-status', 'playlistNotFound')->click('@youtube-extras-retry');
    waitForCondition($page, '!document.querySelector(\'[data-testid="youtube-extras-retry"]\').disabled');
    $page->assertNoJavaScriptErrors();
    Queue::assertPushed(CompleteYouTubeVideo::class, fn (CompleteYouTubeVideo $job) => $job->retryFailed);
    expect($post->fresh()->platform_post_id)->toBe('saved-video');
    expect($post->status)->toBe(Status::Published);
});
