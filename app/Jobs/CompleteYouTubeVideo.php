<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Post\PublishStatus;
use App\Events\PostStatusUpdated;
use App\Models\Post;
use App\Services\Social\YouTubeVideoExtras;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class CompleteYouTubeVideo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public Post $post, public bool $retryFailed = false)
    {
        $this->onQueue($post->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->post->id;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("youtube-extras:{$this->post->id}"))->releaseAfter(30)->expireAfter(960)];
    }

    public function handle(YouTubeVideoExtras $extras): void
    {
        $this->post->refresh();

        if ($this->post->publish_status !== PublishStatus::Published || $this->post->socialAccount === null || ! $extras->pending($this->post)) {
            return;
        }

        $retry = $extras->process($this->post, $this->retryFailed && $this->attempts() <= 1);
        broadcast(new PostStatusUpdated($this->post->fresh()));

        if ($retry && $this->attempts() < $this->tries) {
            $this->release(min(600, 30 * (2 ** max(0, $this->attempts() - 1))));

            return;
        }

        if ($retry) {
            $extras->markUnfinishedFailed($this->post);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $post = Post::find($this->post->id);

        if ($post !== null) {
            app(YouTubeVideoExtras::class)->markUnfinishedFailed($post);
        }
    }
}
