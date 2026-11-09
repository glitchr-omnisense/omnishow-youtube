# omnishow/youtube

YouTube for [omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow)'s video and live
contracts, through the YouTube Data API v3 with an API key. Every endpoint below was read in the
reference on 2026-11-09; no key was used, the tests run on answers written by hand after it.

## The calls

Base: `https://www.googleapis.com/youtube/v3/`; the key in the query (`key=`), as the reference's
examples pass it ([getting started](https://developers.google.com/youtube/v3/getting-started)).

| Contract | Call | Reference |
|---|---|---|
| `video()`, `videos()` | `GET videos?part=snippet,contentDetails,statistics,status,liveStreamingDetails&id=<ids>` (50 a call), `hl=` with `language` | [videos.list](https://developers.google.com/youtube/v3/docs/videos/list) |
| `searchVideos()` | `GET search?part=snippet&type=video&q=&channelId=&relevanceLanguage=&maxResults=&pageToken=` | [search.list](https://developers.google.com/youtube/v3/docs/search/list) |
| `channel()` | `GET channels?part=snippet,contentDetails,statistics&id=UC...` or `&forHandle=@handle` | [channels.list](https://developers.google.com/youtube/v3/docs/channels/list) |
| `channelVideos()` | the channel's `contentDetails.relatedPlaylists.uploads`, then `playlistVideos()` on it | idem |
| `playlist()`, `playlists()` | `GET playlists?part=snippet,contentDetails&id=` or `&channelId=&maxResults=&pageToken=` | [playlists.list](https://developers.google.com/youtube/v3/docs/playlists/list) |
| `playlistVideos()` | `GET playlistItems?part=snippet,contentDetails&playlistId=&maxResults=&pageToken=`, then `videos.list` on the ids | [playlistItems.list](https://developers.google.com/youtube/v3/docs/playlistItems/list) |
| `live()` | `GET search?part=snippet&channelId=&eventType=live&type=video&maxResults=1`, then `videos.list` | search.list (`eventType` needs `type=video`) |
| `schedule()` | `GET search?...&eventType=upcoming&type=video&maxResults=<limit>`, then `videos.list`, sorted by `scheduledStartTime` | idem |

A channel given as `UC` and 22 characters is an identifier; anything else is a handle
(`forHandle`, the `@` optional). A channel is read once per provider instance.

## How a video is read

| Field | Read from |
|---|---|
| `title`, `description` | `snippet.localized.*` (the language asked), else `snippet.title`, `snippet.description` |
| `duration` | `contentDetails.duration`, ISO 8601, in seconds (`P0D` while live: 0) |
| `published` | `snippet.publishedAt` |
| `channel` | `snippet.channelId`, `snippet.channelTitle` |
| `thumbnails` | every key of `snippet.thumbnails` (default, medium, high, standard, maxres; fhd, qhd, uhd where a video has them), smallest first |
| `embeddable`, `privacy` | `status.embeddable`, `status.privacyStatus` |
| `views`, `tags`, `language` | `statistics.viewCount`, `snippet.tags`, `snippet.defaultAudioLanguage` else `defaultLanguage` |
| `url`, `embedUrl`, `privateEmbedUrl` | `https://www.youtube.com/watch?v=<id>`, `https://www.youtube.com/embed/<id>`, `https://www.youtube-nocookie.com/embed/<id>` ([privacy-enhanced mode](https://support.google.com/youtube/answer/171780)) |
| `stream` | when `snippet.liveBroadcastContent` is `live` or `upcoming`, or `liveStreamingDetails` is there: the state (live, upcoming, else ended), `scheduledStartTime`, `scheduledEndTime`, `actualStartTime`, `actualEndTime`, `concurrentViewers` |

A search answers the snippet only: no duration, no views. A video the platform no longer shows
(private, removed) is absent from `videos.list`'s items: it is left out, of a playlist's page too,
whose items keep their `snippet.position`.

A channel: `snippet.customUrl` as its handle and page (`https://www.youtube.com/@handle`),
`statistics.subscriberCount` (null when `hiddenSubscriberCount`; YouTube rounds it to three
significant figures), `statistics.videoCount`, `snippet.country`.

## Errors

A 404 (an unknown playlist: `playlistNotFound`) is an empty page or null. Any other error status is
a `ProviderException` naming the path, never the query where the key sits: a refused key answers 400
(`API_KEY_INVALID` in `error.details`), a spent quota 403 (`quotaExceeded`). A network failure is an
`UnreachableException`, the key masked in its chain.

## Verified, and not

- Verified (the omnishow harness, PHP 8.4, 2026-11-09): every call above recorded as sent, query
  included, through Symfony's `MockHttpClient`; each answer's reading; the errors; `BareTest`;
  PHPStan at max.
- Not verified live: no key was used. The answers follow the reference's resources; the first site
  with a key runs the tests' calls once against YouTube.
