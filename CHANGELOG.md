# Changelog

## Unreleased (1.x)

### Added
- YouTube's usage policy declared as data in `policy.json` (omnisense/contracts' schema, read on
  2026-11-10): 100 search.list calls a day (searchVideos, live, schedule), 10,000 units a day for
  the rest (declared as operations, an upper bound), data kept 30 days at most, YouTube credited as
  the source. `YoutubeProvider` implements `DeclaresUsagePolicyInterface`, the factory gives its
  JsonClient the provider's Backoff (a 429 and its `Retry-After` hold every call). `composer.json`
  names the policy (`extra.omnisense.policy`) and requires `omnisense/contracts`.
- The package, in place of the name held since 2026-10-05: YouTube for omnisense/omnishow's video
  and live contracts through the YouTube Data API v3 with an API key - `VideoInterface`
  (`videos.list`), `VideoSearchInterface` (`search.list`), `ChannelInterface` (`channels.list` by
  id or `forHandle`, the uploads playlist), `PlaylistInterface` (`playlists.list`,
  `playlistItems.list`), `LiveInterface` (`search.list` with `eventType=live` or `upcoming`, then
  `liveStreamingDetails`). Answers written by hand after the reference; no key was used.
