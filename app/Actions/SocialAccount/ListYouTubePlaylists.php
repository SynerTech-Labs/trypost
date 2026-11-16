<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Exceptions\Social\YouTubePublishException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ListYouTubePlaylists
{
    public static function execute(SocialAccount $account): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $playlists = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = Http::withToken($account->access_token)->connectTimeout(3)->timeout(5)
                ->get(config('trypost.platforms.youtube.data_api').'/playlists', array_filter([
                    'part' => 'snippet', 'mine' => 'true', 'maxResults' => 50, 'pageToken' => $cursor,
                ]));

            if ($response->failed()) {
                throw YouTubePublishException::fromApiResponse($response);
            }

            foreach ($response->json('items', []) as $playlist) {
                if (data_get($playlist, 'snippet.channelId') === $account->platform_user_id) {
                    $playlists[] = ['id' => $playlist['id'], 'title' => data_get($playlist, 'snippet.title', '')];
                }
            }

            $cursor = $response->json('nextPageToken');
            $pages++;
        } while (filled($cursor) && $pages < 10);

        return ['playlists' => $playlists, 'truncated' => filled($cursor)];
    }

    public static function cached(SocialAccount $account): array
    {
        return Cache::remember("youtube:playlists:{$account->id}", now()->addMinutes(5), fn (): array => self::execute($account));
    }
}
