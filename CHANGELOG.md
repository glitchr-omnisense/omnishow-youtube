# Changelog

## Unreleased (1.x)

### Changed
- The 30 days are declared as `rights.retention` (`{"maxAge": 2592000, "covers": "API data other
  than authorized data..."}`, the developer policies' III.E.4: stored 30 calendar days at most,
  then deleted or refreshed), where they were `caching.maxTtl`; `caching.maxTtl` is now null.
  omnisense/contracts reads the retention since 16c8da1, so the policy loads with 1568f2c (what
  the sites' vendors hold); `PolicyGuard::cacheTtl()` is bounded by it from **9a96e22** on (with an
  older kernel it no longer gives the 30 days: nothing in omnishow reads it).
- The 10,000 a day are counted in quota units (`"unit": "units"`), each operation costing the units
  of the methods it makes (determine_quota_cost, read 2026-11-10): `channelVideos` 3,
  `playlistVideos`, `playlists`, `live` and `schedule` 2, `searchVideos` 0 (search.list has its own
  bucket, the 100 a day), the others 1; where they were declared as 10,000 operations.
  `YoutubeProvider` implements omnishow's `Policy\MeasuresUsageInterface`: `videos()` counts one
  unit per 50 videos. Needs omnisense/contracts at 5bac28b or later (`unit`, `cost`, `amounts`;
  still `^1.0@dev`, untagged) and omnisense/omnishow's `MeasuresUsageInterface`.

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
