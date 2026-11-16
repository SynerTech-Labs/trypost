<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\CompleteYouTubeVideo;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Platform\ListContentTypesTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Mcp\Tools\SocialAccount\ListYouTubePlaylistsTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

function youtubeMcpUpload(User $user, UploadedFile $file): string
{
    $upload = [];
    TryPostServer::actingAs($user)->tool(RequestMediaUploadTool::class, [])
        ->assertOk()->assertStructuredContent(function (AssertableJson $json) use (&$upload): void {
            $upload = $json->toArray();
            $json->etc();
        });
    test()->post($upload['upload_url'], ['media' => $file])->assertCreated();

    return $upload['upload_token'];
}

test('MCP and REST support a long video with auxiliary uploads scheduling and safe retries', function () {
    Storage::fake();
    Queue::fake([PublishPost::class, CompleteYouTubeVideo::class]);
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $video = Media::factory()->video()->temporaryUpload($workspace)->stored()->create(['meta' => ['duration' => 600, 'width' => 1920, 'height' => 1080]]);
    $thumbnailToken = youtubeMcpUpload($user, UploadedFile::fake()->image('cover.png'));
    $subtitleToken = youtubeMcpUpload($user, UploadedFile::fake()->createWithContent('captions.vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\nHello"));

    TryPostServer::actingAs($user)->tool(ListContentTypesTool::class, [])->assertOk()
        ->assertStructuredContent(function (AssertableJson $json): void {
            $types = collect($json->toArray()['platforms'])->firstWhere('platform', 'youtube')['content_types'];
            expect(collect($types)->pluck('value'))->toContain('youtube_video', 'youtube_short');
            $json->etc();
        });
    TryPostServer::actingAs($user)->tool(CreatePostTool::class, [
        'social_account_id' => $account->id, 'content_type' => 'youtube_video', 'content' => 'Video title',
        'meta' => [
            'title' => 'Long-form title', 'description' => 'Long description', 'privacy_status' => 'unlisted',
            'youtube_thumbnail' => ['upload_token' => $thumbnailToken],
            'youtube_captions' => [['media' => ['upload_token' => $subtitleToken], 'language' => 'en', 'name' => 'English']],
            'youtube_playlists' => ['PL123'],
        ],
    ])->assertOk();
    $post = Post::where('social_account_id', $account->id)->sole();
    TryPostServer::actingAs($user)->tool(AttachMediaFromUploadTool::class, ['post_id' => $post->id, 'upload_token' => $video->upload_token])->assertOk();
    $post->refresh();
    expect($post->fresh()->content_type)->toBe(ContentType::YouTubeVideo);
    expect($post->media)->toHaveCount(1);
    expect($post->ownedMedia()->count())->toBe(3);

    TryPostServer::actingAs($user)->tool(UpdatePostTool::class, ['post_id' => $post->id, 'meta' => ['embeddable' => false]])->assertOk();
    TryPostServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id, 'scheduled_at' => now()->addHour()->toIso8601String()])->assertOk();
    expect($post->fresh()->status)->toBe(Status::Scheduled);
    TryPostServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id])->assertOk();
    Queue::assertPushed(PublishPost::class);
    $this->withHeaders(parityApi($token))->getJson(route('api.posts.show', $post))->assertOk()
        ->assertJsonPath('content_type', 'youtube_video')->assertJsonPath('meta.title', 'Long-form title')
        ->assertJsonPath('meta.youtube_captions.0.language', 'en')->assertJsonPath('meta.youtube_playlists.0', 'PL123');

    $post->update(['status' => Status::Published]);
    $post->writePublication(['publish_status' => PublishStatus::Published, 'platform_post_id' => 'saved-video']);
    TryPostServer::actingAs($user)->tool(PublishPostTool::class, ['post_id' => $post->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('video_id', 'saved-video')->where('extras_queued', true)->etc());
    Queue::assertPushed(CompleteYouTubeVideo::class);
});

test('MCP playlist discovery paginates filters other channels and hides another workspace', function () {
    Queue::fake();
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id, 'token_expires_at' => now()->addDay()]);
    Http::fake([config('trypost.platforms.youtube.data_api').'/playlists*' => Http::sequence()
        ->push(['items' => [
            ['id' => 'PL123', 'snippet' => ['title' => 'My playlist', 'channelId' => $account->platform_user_id]],
            ['id' => 'PLforeign', 'snippet' => ['title' => 'Other channel', 'channelId' => 'other']],
        ], 'nextPageToken' => 'next'])
        ->push(['items' => [['id' => 'PL456', 'snippet' => ['title' => 'Another playlist', 'channelId' => $account->platform_user_id]]]]),
    ]);

    TryPostServer::actingAs($user)->tool(ListYouTubePlaylistsTool::class, ['account_id' => $account->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('playlists', 2)->where('playlists.0.id', 'PL123')->where('playlists.1.id', 'PL456')->where('truncated', false));
    Http::assertSentCount(2);
    $this->withHeaders(parityApi($token))->getJson(route('api.youtube.playlists', $account))->assertOk()
        ->assertJsonPath('youtubePlaylists.playlists.0.id', 'PL123');
    $foreign = SocialAccount::factory()->youtube()->create();
    TryPostServer::actingAs($user)->tool(ListYouTubePlaylistsTool::class, ['account_id' => $foreign->id])->assertHasErrors(['YouTube account not found.']);
    auth()->forgetGuards();
    $this->withHeaders(parityApi($token))->getJson(route('api.youtube.playlists', $foreign))->assertNotFound();
    Http::assertSentCount(2);
});
