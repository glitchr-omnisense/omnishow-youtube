# omnishow/youtube

[omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow)'s YouTube, under any PHP host, through the
[YouTube Data API v3](https://developers.google.com/youtube/v3/docs) with an API key (public data,
no user signed in). It implements the **video** and **live** contracts:

- **a video's record** (`videos.list`, 50 a call): title, description, duration, thumbnails, the
  date published, its channel, tags, views, privacy, embeddable, its page, its player and the
  privacy-enhanced player on `www.youtube-nocookie.com`;
- **a search** (`search.list`, `type=video`): short records, a page token for the next page;
- **a channel** by its identifier (`UC...`) or its `@handle` (`channels.list`'s `forHandle`), and
  **its uploads**, newest first (its uploads playlist, then `videos.list` for the whole records);
- **playlists**: a channel's (`playlists.list`), a playlist's videos in its order
  (`playlistItems.list`, then `videos.list`);
- **live**: what a channel broadcasts now (`search.list` with `eventType=live`, then
  `videos.list`'s `liveStreamingDetails`: viewers, start) and **its schedule** (`eventType=upcoming`,
  soonest first).

```php
use Omnishow\Model\VideoRef;
use Omnishow\Youtube\YoutubeFactory;

$youtube = (new YoutubeFactory($httpClient))->create(['key' => getenv('YOUTUBE_KEY'), 'language' => 'fr']);
$video = $youtube->video(VideoRef::fromUrl('https://youtu.be/aqz-KE-bpKQ'));
echo $video->embed(private: true);       // https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ
$uploads = $youtube->channelVideos('@GoogleDevelopers', limit: 12);
$now = $youtube->live('@GoogleDevelopers');  // null when off air
```

Options: `key` (required: an API key of a Google Cloud project with the YouTube Data API v3
enabled), `language` (`hl`: titles and descriptions in this language where the owner translated
them), `api` (`https://www.googleapis.com/youtube/v3/`). See [docs/index.md](docs/index.md).

## Quota

Each call costs a unit of the project's 10,000 a day; since 2026-06-01 a search costs one unit of a
bucket of its own, 100 searches a day by default ([quota costs](https://developers.google.com/youtube/v3/determine_quota_cost)).
`live()` and `schedule()` make one search each: a page that shows whether a channel is live caches
the answer.

## Not here

`liveBroadcasts.list` needs the channel owner's OAuth (`youtube.readonly`): it is not used, and the
live contract reads public broadcasts through `search.list` instead. Live chat, comments, captions
and uploading are out of the contract.

## Usage policy

The package declares YouTube's usage policy as data, in [policy.json](policy.json)
(omnisense/contracts' schema, read at its sources on 2026-11-10): rate limits 100 search.list calls
a day (searchVideos, live, schedule) and 10,000 units a day for every other method, each operation
costing the units of the methods it makes (`channelVideos` 3, `playlistVideos`, `playlists`, `live`
and `schedule` 2, `searchVideos` 0, the others 1; `videos()` one per 50 videos); data not
authorised by its owner kept 30 days at most, no redistribution, YouTube shown as the source with
its brand features and a link to its Terms of Service, no ads in or on the player. Through
omnishow's registry every call is held to it: the 101st search of a day is refused before it reaches
YouTube, a 429 and its `Retry-After` hold every next call until the wait is over
(`UsageLimitReachedException`), and a site may tighten the policy in its configuration (`'policy' =>
[...]`), never loosen it. What the terms do not say is declared unknown; the sources and the details
are in the file's `provenance`.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Google LLC (YouTube). It reaches their service on behalf of whoever runs it, and it
does not redistribute their data beyond the short excerpts written by hand as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the YouTube API Services's
terms ([YouTube API Services Terms of
Service](https://developers.google.com/youtube/terms/api-services-terms-of-service), the [Developer
Policies](https://developers.google.com/youtube/terms/developer-policies) and the [Required Minimum
Functionality](https://developers.google.com/youtube/terms/required-minimum-functionality): an
embedded player sends the page's Referer, `strict-origin-when-cross-origin`, and is at least
200x200 pixels), for obtaining whatever they require (a project, a key, a quota), and for any use
made of what it returns. The authors publish it free of charge, make no commercial use of it, and
accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
