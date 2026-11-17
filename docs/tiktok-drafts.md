# TikTok video drafts

## Audit and API decisions

Reviewed the TikTok publisher, OAuth callback and configured scopes, content types, shared composition/meta validation, scheduled publication jobs, retry command, recovery sweep, public-ID resolver, media ownership/pruning, analytics observer, composer settings, planner badges, API resources and MCP post tools.

The existing configuration already requests `video.upload`. Connections store the granted scopes. Direct Post still requires its existing grants; draft transfers require `video.upload`. The existing video/photo Direct Post endpoints, privacy requirements and ID resolver stay in place.

`meta.posting_mode` accepts `direct_post` (also the behavior when omitted) or `draft_upload`, only for `tiktok_video`. No new platform, content type, database column or migration is needed. Scheduled, edited and duplicated posts keep the mode through the existing metadata flow. Duplicates do not inherit transfer checkpoints or public IDs.

Official references checked on 2026-11-17:

- [Upload prerequisites](https://developers.tiktok.com/docs/en/content-posting-api-get-started-upload-content): the app needs approval for `video.upload`, and the creator must grant that scope. The creator finishes through an inbox notification. This workflow does not require switching the creator's account to private.
- [Video upload endpoint](https://developers.tiktok.com/docs/en/content-posting-api-reference-upload-video): `POST /v2/post/publish/inbox/video/init/` accepts `source_info`, including `PULL_FROM_URL`. It does not accept captions, hashtags, privacy or Direct Post settings. The endpoint returns a transfer `publish_id`, not a video ID. TikTok limits initialization to six requests per minute per token and caps pending shares.
- [Transfer guide](https://developers.tiktok.com/docs/en/content-posting-api-media-transfer-guide): URLs must use HTTPS, must not redirect, and must belong to a domain or URL prefix verified in the TikTok developer app. TikTok downloads can take up to one hour. Existing post-owned media is retained; no extra transcoding, server-side transfer or temporary copy is added. Storage operators must keep the source reachable for at least an hour, including any signed URL lifetime. TikTok enforces domain verification; this code cannot inspect the developer dashboard or certify a deployment's configuration.
- [Status API](https://developers.tiktok.com/docs/en/content-posting-api-reference-get-video-status): `PROCESSING_DOWNLOAD` and `PROCESSING_UPLOAD` mean transfer in progress. `SEND_TO_USER_INBOX` means delivery, with manual publishing still required. Only `PUBLISH_COMPLETE` confirms the creator posted. Public IDs may be absent, delayed by moderation, or plural because the creator can make multiple posts from the same draft.

## Lifecycle and retry policy

The publisher retains the existing short transfer polling schedule and one-hour budget. It checkpoints the accepted `publish_id` and reconciles that ID on retries. An initialization attempt is recorded before making the request. If its response is lost or ambiguous, automatic and operator retries cannot initialize another transfer; check TikTok's inbox first. TikTok does not document an initialization idempotency key or an API to recover an ID from a lost initialization response, so exactly-once remote acceptance cannot be proven across that network boundary. The conservative policy prevents automatic duplicate drafts.

Inbox delivery is `publish_status: inbox_delivered` with lifecycle `status: publishing`, an empty `published_at` and no public URL. A first completion check runs after one minute; the existing reconciliation sweep then checks delivered drafts at most hourly for 30 days from `submitted_at`. A stale or unavailable status API never turns delivery into publication. When monitoring expires the delivered state remains; the UI explains the monitoring window. Expiration is a TryPost monitoring policy, not a claimed TikTok draft lifetime.

`PUBLISH_COMPLETE` uses the existing finalizer, notifications and analytics. A completion without a public ID keeps its internal transfer ID and has no public URL. Later checks can resolve an actual public ID. The full list is exposed in system-owned `meta.tiktok_public_post_ids`; the first confirmed public ID is the canonical link and analytics identity. Additional public posts remain eligible for normal network discovery. Draft captions are not used to guess public IDs. `FAILED` records TikTok's actionable reason and keeps the checkpoint without inventing a link.

## API and MCP

Use the existing post creation, update, scheduling and publish tools with:

```json
{
  "content_type": "tiktok_video",
  "meta": { "posting_mode": "draft_upload" }
}
```

For batch creation put those fields in each TikTok destination. Draft mode does not require `privacy_level`; Direct Post still does. `content` remains the prepared caption in TryPost and is available to copy from post details. The scheduled time is the transfer time, not a guarantee of public publication. Open the TikTok inbox notification and enter or confirm the caption, hashtags, privacy and other details in TikTok.

## Deployment prerequisites and verification limits

The owner must confirm `video.upload` approval in the TikTok developer dashboard, retain it in any overridden `TIKTOK_SCOPES`, reconnect existing channels to grant it, and verify the actual storage domain or URL prefix. Code cannot bypass permission approval or grant consent. No live credentials or publication are used in automated verification, and no VPS or production configuration is changed by this feature.

## Verification

Focused PostgreSQL checks passed for draft lifecycle, API/MCP parity and workspace isolation, OAuth grants, all 16 locales and copy budgets. MySQL 8.4 passed 199 tests covering draft lifecycle, existing video/photo Direct Post, API/MCP network validation and TikTok ID resolution. The broader PostgreSQL publishing/finalizer/authorization run passed 639 tests and failed four existing `attach-media-from-url-tool` authorization fixtures on `active_url` validation. The same four failures reproduce on the untouched base commit `5db1ec40` in a separate container and database; no unrelated media validation was changed.

Browser coverage exercises selecting and saving draft mode, missing upload-grant instructions, the inbox queue heading and badge, manual-completion instructions, and an empty publication timestamp in English and German. TypeScript, changed-file ESLint/Prettier, Pint and the Vite production build are also checked. TikTok HTTP requests are mocked. The production build uses the repository's CI Reverb test values; production Reverb configuration is unchanged.

The complete repository suite remains the PR CI gate. Do not merge or deploy this feature until required CI passes and the deployment prerequisites above are satisfied.
