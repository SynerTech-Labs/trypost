<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\ListYouTubePlaylists;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Name('list-youtube-playlists-tool')]
#[Description('List existing playlists owned by a connected YouTube channel. Pass selected ids as meta.youtube_playlists on a youtube_video post. At most 500 playlists are returned; truncated indicates more exist. Playlist assignment happens after upload and can be retried with publish-post-tool without uploading the video again.')]
class ListYouTubePlaylistsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['account_id' => ['required', 'uuid']]);
        $account = SocialAccount::where('workspace_id', $workspace->id)->where('platform', Platform::YouTube)->find($validated['account_id']);

        if ($account === null) {
            return Response::error('YouTube account not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $account, 'YouTube account not found.')) {
            return $denied;
        }

        try {
            return Response::structured(ListYouTubePlaylists::cached($account));
        } catch (Throwable) {
            return Response::error(__('posts.form.youtube.playlists_failed'));
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return ['account_id' => $schema->string()->required()->description('UUID of the connected YouTube account.')];
    }
}
