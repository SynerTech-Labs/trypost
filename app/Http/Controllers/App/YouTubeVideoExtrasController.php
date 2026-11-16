<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Post\PublishStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CompleteYouTubeVideo;
use App\Models\Post;
use App\Services\Social\YouTubeVideoExtras;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class YouTubeVideoExtrasController extends Controller
{
    public function __invoke(Post $post, YouTubeVideoExtras $extras): JsonResponse
    {
        $this->authorize('update', $post);
        $this->authorize('approvePosts', $post->workspace);
        abort_unless($post->publish_status === PublishStatus::Published && $extras->pending($post), Response::HTTP_UNPROCESSABLE_ENTITY);
        CompleteYouTubeVideo::dispatch($post, retryFailed: true);

        return response()->json(['queued' => true], Response::HTTP_ACCEPTED);
    }
}
