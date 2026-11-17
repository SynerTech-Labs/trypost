<?php

declare(strict_types=1);

use App\Actions\Media\SyncOwnedMedia;
use App\Actions\Post\DuplicatePost;
use App\Enums\Media\Type;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostPlatformMetaRules;
use App\Support\TimedSubtitles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('timed subtitles validate their encoding structure and timestamp ranges', function (string $name, string $text, bool $valid) {
    expect(TimedSubtitles::valid($text, $name))->toBe($valid);
})->with([
    'srt' => ['captions.srt', "1\n00:00:00,000 --> 00:00:02,000\nHello", true],
    'vtt' => ['captions.vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\nHello", true],
    'utf8 BOM and CRLF' => ['captions.srt', "\xEF\xBB\xBF1\r\n00:00:00,000 --> 00:00:02,000\r\nBonjour", true],
    'multiple tracks cues' => ['captions.vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\nHello\n\n00:02.000 --> 00:03.000\nWorld", true],
    'missing timestamps' => ['captions.srt', 'Only a transcript', false],
    'backwards time' => ['captions.srt', "1\n00:00:02,000 --> 00:00:01,000\nHello", false],
    'invalid seconds' => ['captions.srt', "1\n00:00:99,000 --> 00:01:40,000\nHello", false],
    'empty cue' => ['captions.srt', "1\n00:00:01,000 --> 00:00:02,000\n", false],
    'invalid utf8' => ['captions.srt', "1\n00:00:01,000 --> 00:00:02,000\n\xFF", false],
    'missing vtt header' => ['captions.vtt', "00:00.000 --> 00:02.000\nHello", false],
    'wrong extension' => ['captions.txt', "1\n00:00:00,000 --> 00:00:02,000\nHello", false],
    'too large' => ['captions.srt', str_repeat('a', TimedSubtitles::MAX_BYTES + 1), false],
]);

test('auxiliary media is adopted separately and survives ordinary saves and duplication', function () {
    Storage::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account, ContentType::YouTubeVideo)->create(['user_id' => $user->id]);
    $thumbnail = $workspace->addMedia(UploadedFile::fake()->image('cover.png'), Media::COLLECTION_UPLOADS);
    $subtitle = $workspace->addMedia(UploadedFile::fake()->createWithContent('captions.srt', "1\n00:00:00,000 --> 00:00:02,000\nHello"), Media::COLLECTION_UPLOADS);
    $thumbnail->issueUploadToken();
    $subtitle->issueUploadToken();
    $post->update(['meta' => [
        'youtube_thumbnail' => ['upload_token' => $thumbnail->upload_token],
        'youtube_captions' => [['media' => ['upload_token' => $subtitle->upload_token], 'language' => 'en']],
    ]]);

    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [], $batch));
    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [], $batch));

    expect($post->fresh()->media)->toBeEmpty();
    expect($post->ownedMedia()->count())->toBe(2);
    expect($subtitle->fresh()->post_id)->toBe($post->id);
    expect($subtitle->fresh()->type)->toBe(Type::Subtitle);
    expect($subtitle->fresh()->upload_token)->toBeNull();
    expect(data_get($post->meta, 'youtube_thumbnail.id'))->toBe($thumbnail->id);
    $copy = DuplicatePost::execute($post->fresh(), $user);
    expect($copy->ownedMedia()->count())->toBe(2);
    expect(data_get($copy->meta, 'youtube_thumbnail.id'))->not->toBe($thumbnail->id);
    expect($post->fresh()->ownedMedia()->count())->toBe(2);
    $post->update(['meta' => ['youtube_thumbnail' => null, 'youtube_captions' => []]]);
    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [], $batch));
    expect($post->ownedMedia()->count())->toBe(0);
    expect($copy->ownedMedia()->count())->toBe(2);
});

test('auxiliary references cannot borrow files from another workspace or override server file metadata', function (bool $foreign) {
    Storage::fake();
    $account = SocialAccount::factory()->youtube()->create();
    $post = Post::factory()->forAccount($account, ContentType::YouTubeVideo)->create();
    $workspace = $foreign ? Workspace::factory()->create() : $post->workspace;
    $media = Media::factory()->video()->temporaryUpload($workspace)->stored()->create();
    $post->update(['meta' => ['youtube_thumbnail' => ['id' => $media->id, 'mime_type' => 'image/jpeg', 'size' => 1, 'url' => 'https://attacker.example/cover.jpg']]]);

    expect(fn () => MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [], $batch)))
        ->toThrow(ValidationException::class);

    expect($media->fresh()->post_id)->toBeNull();
    expect($post->ownedMedia()->count())->toBe(0);
})->with([true, false]);

test('signed subtitle uploads reject invalid timing before storing a file', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();
    $url = URL::temporarySignedRoute('api.uploads.store', now()->addMinutes(10), ['token' => (string) Str::uuid(), 'workspace_id' => $workspace->id]);

    $this->withHeader('Accept', 'application/json')->post($url, ['media' => UploadedFile::fake()->createWithContent('captions.srt', "1\n00:00:02,000 --> 00:00:01,000\nBackwards")])
        ->assertUnprocessable()->assertJsonValidationErrors('media');

    expect(Media::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('shared metadata rules require subtitle languages and reject duplicate track identities', function () {
    $media = ['id' => (string) Str::uuid()];
    $missing = Validator::make(['meta' => ['youtube_captions' => [['media' => $media]]]], PostPlatformMetaRules::rules());
    $duplicates = Validator::make(['meta' => ['youtube_captions' => [
        ['media' => $media, 'language' => 'en', 'name' => 'English'],
        ['media' => $media, 'language' => 'en', 'name' => 'English'],
    ]]], PostPlatformMetaRules::rules());

    expect($missing->fails())->toBeTrue();
    expect($missing->errors()->has('meta.youtube_captions.0.language'))->toBeTrue();
    expect($duplicates->fails())->toBeTrue();
    expect($duplicates->errors()->has('meta.youtube_captions'))->toBeTrue();
});
