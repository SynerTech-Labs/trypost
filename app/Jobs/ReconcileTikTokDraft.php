<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\AssignTikTokVideoId;
use App\Actions\Post\FinalizePostPublication;
use App\Enums\Post\PublishStatus;
use App\Enums\TikTok\PublishStatus as TikTokStatus;
use App\Events\PostStatusUpdated;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\Social\TikTokPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\TikTokPublisher;
use App\Support\Social\PublishCheckpoint;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ReconcileTikTokDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int TRACK_WITHIN_DAYS = 30;

    public int $timeout = 900;

    public int $uniqueFor = 960;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Post $post)
    {
        $this->onQueue($post->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->post->id;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->post->id))->releaseAfter(60)->expireAfter(960)];
    }

    public function handle(TikTokPublisher $publisher, AssignTikTokVideoId $assignVideoId): void
    {
        $this->post->refresh();
        $post = $this->post;

        if (! $post->sendsTikTokDraft()
            || ! in_array($post->publish_status, [PublishStatus::InboxDelivered, PublishStatus::Published], true)
            || blank($post->submitted_at)
            || $post->submitted_at->lessThanOrEqualTo(now()->subDays(self::TRACK_WITHIN_DAYS))
            || ! $post->socialAccount()->connected()->exists()) {
            return;
        }

        try {
            $data = $this->fetchStatus($publisher);
            $status = TikTokStatus::tryFrom((string) data_get($data, 'status'));

            if ($status === TikTokStatus::Failed && $post->publish_status === PublishStatus::InboxDelivered) {
                $exception = TikTokPublishException::fromFailReason((string) data_get($data, 'fail_reason', 'Unknown error'));
                $post->markPublicationFailed($exception->userMessage, [
                    ...($post->error_context ?? []),
                    PublishCheckpoint::TIKTOK_STATUS => $status->value,
                    'platform_error_code' => $exception->platformErrorCode,
                ]);
                app(FinalizePostPublication::class)->handle($post);

                return;
            }

            if ($status !== TikTokStatus::PublishComplete) {
                return;
            }

            $context = $post->error_context;
            $ids = array_values(array_unique([
                ...(array) data_get($post->meta, 'tiktok_public_post_ids', []),
                ...$publisher->publicPostIds($data),
            ]));

            if ($post->publish_status !== PublishStatus::Published) {
                $post->markPublicationPublished((string) $post->platform_post_id);
            }

            $post->writePublication([
                'error_context' => [...($context ?? []), PublishCheckpoint::TIKTOK_STATUS => $status->value],
                'meta' => [...($post->meta ?? []), 'tiktok_public_post_ids' => $ids],
                'error_message' => null,
            ]);

            if (filled($ids) && ! ctype_digit((string) $post->platform_post_id)) {
                rescue(fn () => $assignVideoId->handle($post, $ids[0]));
            }

            app(FinalizePostPublication::class)->handle($post);
        } catch (Throwable $exception) {
            if ($exception instanceof SocialPublishException) {
                $post->writePublication(['error_message' => $exception->userMessage]);
            }

            report($exception);
        } finally {
            $post->writePublication(['last_reconciled_at' => now()]);
            PostStatusUpdated::dispatch($post->fresh());
        }
    }

    private function fetchStatus(TikTokPublisher $publisher): array
    {
        try {
            return $publisher->draftStatus($this->post);
        } catch (TokenExpiredException) {
            try {
                app(ConnectionVerifier::class)->verify($this->post->socialAccount);
            } catch (TokenExpiredException $exception) {
                $this->post->socialAccount->markAsTokenExpired();

                throw $exception;
            }

            return $publisher->draftStatus($this->post);
        }
    }
}
