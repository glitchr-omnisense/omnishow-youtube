<?php

namespace Omnishow\Youtube;

use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnishow\Capabilities;
use Omnishow\ChannelInterface;
use Omnishow\Exception\NotSupportedException;
use Omnishow\Http\Data;
use Omnishow\Http\JsonClient;
use Omnishow\LiveInterface;
use Omnishow\Model\Channel;
use Omnishow\Model\Image;
use Omnishow\Model\ImageKind;
use Omnishow\Model\LiveState;
use Omnishow\Model\Page;
use Omnishow\Model\Platform;
use Omnishow\Model\Playlist;
use Omnishow\Model\Stream;
use Omnishow\Model\Video;
use Omnishow\Model\VideoQuery;
use Omnishow\Model\VideoRef;
use Omnishow\PlaylistInterface;
use Omnishow\ProviderInterface;
use Omnishow\VideoInterface;
use Omnishow\VideoSearchInterface;

/**
 * YouTube through the YouTube Data API v3 with an API key (public data, no
 * user signed in): videos (videos.list, 50 a call), a search
 * (search.list), a channel by its identifier or @handle (channels.list),
 * its uploads (its uploads playlist, read with playlistItems.list, then
 * videos.list for the whole records), playlists (playlists.list,
 * playlistItems.list), what a channel broadcasts live and has scheduled
 * (search.list's eventType=live or upcoming, then videos.list's
 * liveStreamingDetails). liveBroadcasts.list needs the owner's OAuth: not
 * used.
 *
 * Quota: each call costs a unit of the project's 10,000 a day, a search one
 * of its "Search Queries" bucket (100 a day by default); live() and
 * schedule() make one search each.
 */
final class YoutubeProvider implements ProviderInterface, VideoInterface, VideoSearchInterface, ChannelInterface, PlaylistInterface, LiveInterface, DeclaresUsagePolicyInterface
{
    /** YouTube's usage policy, as its terms read on the day it says (provenance.verifiedAt). */
    public const POLICY = __DIR__.'/policy.json';

    private static ?UsagePolicy $policy = null;

    private const VIDEO_PARTS = 'snippet,contentDetails,statistics,status,liveStreamingDetails';
    private const CHANNEL = '~^UC[A-Za-z0-9_-]{22}$~';

    /** @var array<string, ?Data> channels.list's answers, by the identifier or handle asked */
    private array $channels = [];

    public function __construct(private readonly JsonClient $json, private readonly string $key, private readonly string $api = 'https://www.googleapis.com/youtube/v3/', private readonly ?string $language = null)
    {
    }

    public function getName(): string
    {
        return 'youtube';
    }

    public function getTitle(): string
    {
        return 'YouTube';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            images: true,
            terms: ['https://developers.google.com/youtube/terms/api-services-terms-of-service', 'https://developers.google.com/youtube/terms/developer-policies', 'https://developers.google.com/youtube/terms/required-minimum-functionality'],
            video: true,
            videoSearch: true,
            channels: true,
            playlists: true,
            live: true,
            schedule: true,
            platforms: [Platform::YouTube],
            policy: $this->usagePolicy(),
        );
    }

    public function video(VideoRef $ref): ?Video
    {
        return $this->videos([$ref])[0] ?? null;
    }

    public function videos(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (!$this->capabilities()->hosts($ref)) {
                throw NotSupportedException::video($this->getTitle(), $ref);
            }
            $ids[] = $ref->id;
        }
        $found = [];
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
            foreach ($this->call('videos', ['part' => self::VIDEO_PARTS, 'id' => implode(',', $chunk), 'hl' => $this->language])?->list('items') ?? [] as $item) {
                $video = $this->video0($item);
                $found[$video->ref->id] = $video;
            }
        }

        return array_values(array_filter(array_map(static fn (string $id) => $found[$id] ?? null, $ids)));
    }

    public function searchVideos(VideoQuery $query): Page
    {
        $answer = $this->call('search', ['part' => 'snippet', 'type' => 'video', 'q' => $query->text, 'channelId' => $query->channel, 'relevanceLanguage' => $query->language, 'maxResults' => $query->limit, 'pageToken' => $query->page]);
        $videos = [];
        foreach ($answer?->list('items') ?? [] as $item) {
            $id = $item->string('id.videoId');
            if (null !== $id) {
                $videos[] = $this->video0($item, $id);
            }
        }

        return new Page($videos, $answer?->string('nextPageToken'), $answer?->int('pageInfo.totalResults'));
    }

    public function channel(string $channel): ?Channel
    {
        $item = $this->channelData($channel);

        return null === $item ? null : $this->channel0($item);
    }

    public function channelVideos(string $channel, ?string $page = null, int $limit = 25): Page
    {
        $uploads = $this->channelData($channel)?->string('contentDetails.relatedPlaylists.uploads');

        return null === $uploads ? new Page() : $this->playlistVideos($uploads, $page, $limit);
    }

    public function playlist(string $id): ?Playlist
    {
        $item = $this->call('playlists', ['part' => 'snippet,contentDetails', 'id' => $id, 'hl' => $this->language])?->list('items')[0] ?? null;

        return null === $item ? null : $this->playlist0($item);
    }

    public function playlists(string $channel, ?string $page = null, int $limit = 25): Page
    {
        $id = $this->channelId($channel);
        if (null === $id) {
            return new Page();
        }
        $answer = $this->call('playlists', ['part' => 'snippet,contentDetails', 'channelId' => $id, 'maxResults' => Page::limit($limit, 50), 'pageToken' => $page, 'hl' => $this->language]);

        return new Page(array_map($this->playlist0(...), $answer?->list('items') ?? []), $answer?->string('nextPageToken'), $answer?->int('pageInfo.totalResults'));
    }

    public function playlistVideos(string $id, ?string $page = null, int $limit = 25): Page
    {
        // An unknown playlist is a 404 (playlistNotFound): null, an empty page.
        $answer = $this->call('playlistItems', ['part' => 'snippet,contentDetails', 'playlistId' => $id, 'maxResults' => Page::limit($limit, 50), 'pageToken' => $page]);
        $positions = [];
        foreach ($answer?->list('items') ?? [] as $item) {
            $videoId = $item->string('contentDetails.videoId') ?? $item->string('snippet.resourceId.videoId');
            if (null !== $videoId) {
                $positions[$videoId] = $item->int('snippet.position');
            }
        }
        $videos = [];
        foreach ([] === $positions ? [] : $this->videos(array_map(VideoRef::youtube(...), array_map('strval', array_keys($positions)))) as $video) {
            $videos[] = new Video($video->ref, $video->title, $video->source, $video->description, $video->duration, $video->published, $video->channel, $video->thumbnails, $video->url, $video->embedUrl, $video->privateEmbedUrl, $video->embeddable, $video->tags, $video->views, $video->language, $video->privacy, $video->stream, $positions[$video->ref->id] ?? null);
        }

        return new Page($videos, $answer?->string('nextPageToken'), $answer?->int('pageInfo.totalResults'));
    }

    public function live(string $channel): ?Stream
    {
        return $this->broadcasts($channel, 'live', 1)[0] ?? null;
    }

    public function schedule(string $channel, int $limit = 10): array
    {
        $upcoming = array_values(array_filter($this->broadcasts($channel, 'upcoming', Page::limit($limit, 50)), static fn (Stream $stream) => LiveState::Upcoming === $stream->state));
        usort($upcoming, static fn (Stream $a, Stream $b) => $a->scheduled <=> $b->scheduled);

        return $upcoming;
    }

    /**
     * The channel's broadcasts in this state (search.list's eventType, which
     * needs type=video), read whole with videos.list for their
     * liveStreamingDetails.
     *
     * @return list<Stream>
     */
    private function broadcasts(string $channel, string $eventType, int $limit): array
    {
        $id = $this->channelId($channel);
        if (null === $id) {
            return [];
        }
        $ids = [];
        foreach ($this->call('search', ['part' => 'snippet', 'channelId' => $id, 'eventType' => $eventType, 'type' => 'video', 'maxResults' => $limit])?->list('items') ?? [] as $item) {
            if (null !== $videoId = $item->string('id.videoId')) {
                $ids[] = VideoRef::youtube($videoId);
            }
        }

        return array_values(array_filter(array_map(static fn (Video $video) => $video->stream, [] === $ids ? [] : $this->videos($ids))));
    }

    private function channelId(string $channel): ?string
    {
        return preg_match(self::CHANNEL, $channel) ? $channel : $this->channelData($channel)?->string('id');
    }

    /** channels.list by id (UC...) or by handle (forHandle, the @ optional), once per channel. */
    private function channelData(string $channel): ?Data
    {
        $channel = trim($channel);
        if (\array_key_exists($channel, $this->channels)) {
            return $this->channels[$channel];
        }
        $filter = preg_match(self::CHANNEL, $channel) ? ['id' => $channel] : ['forHandle' => $channel];

        return $this->channels[$channel] = $this->call('channels', ['part' => 'snippet,contentDetails,statistics'] + $filter + ['hl' => $this->language])?->list('items')[0] ?? null;
    }

    /**
     * A video from videos.list's item, or from a search's (its id given:
     * id.videoId), which carries the snippet only.
     */
    private function video0(Data $item, ?string $id = null): Video
    {
        $id ??= (string) $item->string('id');
        $snippet = $item->data('snippet');
        $ref = VideoRef::youtube($id);
        $channelId = $snippet->string('channelId');
        $channel = null === $channelId ? null : new Channel(Platform::YouTube, $channelId, (string) $snippet->string('channelTitle'), url: 'https://www.youtube.com/channel/'.$channelId, source: 'youtube');
        $thumbnails = self::thumbnails($snippet->data('thumbnails'));
        $content = $snippet->string('liveBroadcastContent');
        $live = $item->data('liveStreamingDetails');
        $stream = null;
        if ('live' === $content || 'upcoming' === $content || [] !== $live->toArray()) {
            $stream = new Stream(
                platform: Platform::YouTube,
                state: match ($content) {
                    'live' => LiveState::Live,
                    'upcoming' => LiveState::Upcoming,
                    default => LiveState::Ended,
                },
                source: 'youtube',
                channel: $channel,
                title: $snippet->string('localized.title') ?? $snippet->string('title'),
                id: $id,
                video: $ref,
                scheduled: $live->dateTime('scheduledStartTime'),
                scheduledEnd: $live->dateTime('scheduledEndTime'),
                started: $live->dateTime('actualStartTime'),
                ended: $live->dateTime('actualEndTime'),
                viewers: $live->int('concurrentViewers'),
                thumbnail: [] === $thumbnails ? null : $thumbnails[\count($thumbnails) - 1],
                url: $ref->url(),
                embedUrl: $ref->embedUrl(),
                privateEmbedUrl: $ref->embedUrl(true),
            );
        }

        return new Video(
            ref: $ref,
            title: (string) ($snippet->string('localized.title') ?? $snippet->string('title') ?? $id),
            source: 'youtube',
            description: $snippet->string('localized.description') ?? $snippet->string('description'),
            duration: $item->duration('contentDetails.duration'),
            published: $snippet->dateTime('publishedAt'),
            channel: $channel,
            thumbnails: $thumbnails,
            embeddable: $item->bool('status.embeddable'),
            tags: $snippet->strings('tags'),
            views: $item->int('statistics.viewCount'),
            language: $snippet->string('defaultAudioLanguage') ?? $snippet->string('defaultLanguage'),
            privacy: $item->string('status.privacyStatus'),
            stream: $stream,
        );
    }

    private function channel0(Data $item): Channel
    {
        $id = (string) $item->string('id');
        $snippet = $item->data('snippet');
        $handle = $snippet->string('customUrl');

        return new Channel(
            platform: Platform::YouTube,
            id: $id,
            title: (string) ($snippet->string('localized.title') ?? $snippet->string('title') ?? $id),
            handle: $handle,
            description: $snippet->string('localized.description') ?? $snippet->string('description'),
            thumbnails: self::thumbnails($snippet->data('thumbnails')),
            url: null !== $handle && str_starts_with($handle, '@') ? 'https://www.youtube.com/'.$handle : 'https://www.youtube.com/channel/'.$id,
            published: $snippet->dateTime('publishedAt'),
            country: $snippet->string('country'),
            subscribers: true === $item->bool('statistics.hiddenSubscriberCount') ? null : $item->int('statistics.subscriberCount'),
            videos: $item->int('statistics.videoCount'),
            source: 'youtube',
        );
    }

    private function playlist0(Data $item): Playlist
    {
        $id = (string) $item->string('id');
        $snippet = $item->data('snippet');
        $channelId = $snippet->string('channelId');

        return new Playlist(
            platform: Platform::YouTube,
            id: $id,
            title: (string) ($snippet->string('localized.title') ?? $snippet->string('title') ?? $id),
            source: 'youtube',
            description: $snippet->string('localized.description') ?? $snippet->string('description'),
            channel: null === $channelId ? null : new Channel(Platform::YouTube, $channelId, (string) $snippet->string('channelTitle'), url: 'https://www.youtube.com/channel/'.$channelId, source: 'youtube'),
            thumbnails: self::thumbnails($snippet->data('thumbnails')),
            count: $item->int('contentDetails.itemCount'),
            url: 'https://www.youtube.com/playlist?list='.rawurlencode($id),
            published: $snippet->dateTime('publishedAt'),
        );
    }

    /**
     * The thumbnails under their keys (default, medium, high, standard,
     * maxres, and fhd, qhd, uhd where a video has them), smallest first.
     *
     * @return list<Image>
     */
    private static function thumbnails(Data $thumbnails): array
    {
        $images = [];
        foreach ($thumbnails->keys() as $key) {
            $one = $thumbnails->data($key);
            if (null !== $url = $one->string('url')) {
                $images[] = new Image(ImageKind::Thumbnail, $url, $one->int('width'), $one->int('height'));
            }
        }
        usort($images, static fn (Image $a, Image $b) => ($a->width ?? 0) <=> ($b->width ?? 0));

        return $images;
    }

    /** @param array<string, string|int|null> $query */
    private function call(string $resource, array $query): ?Data
    {
        return $this->json->get(rtrim($this->api, '/').'/'.$resource, $query + ['key' => $this->key]);
    }
}
