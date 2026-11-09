<?php

namespace Omnishow\Youtube\Tests;

use Omnishow\Exception\InvalidConfigException;
use Omnishow\Exception\NotSupportedException;
use Omnishow\Exception\ProviderException;
use Omnishow\Model\LiveState;
use Omnishow\Model\Playlist;
use Omnishow\Model\Stream;
use Omnishow\Model\Video;
use Omnishow\Model\VideoQuery;
use Omnishow\Model\VideoRef;
use Omnishow\Youtube\YoutubeFactory;
use Omnishow\Youtube\YoutubeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * YouTube Data API v3's answers shaped after its reference
 * (developers.google.com/youtube/v3/docs, read on 2026-11-09), values
 * written by hand: no key was used, nothing here was captured from YouTube.
 * Every request is recorded as sent, its query included.
 */
final class YoutubeProviderTest extends TestCase
{
    private const API = 'https://www.googleapis.com/youtube/v3/';
    private const KEY = 'not-a-real-youtube-key';
    private const CHANNEL = 'UCexampleChannel00000001';

    /** @var list<string> "METHOD url", the query included */
    private array $requests = [];

    public function testAVideosWholeRecord(): void
    {
        $video = $this->provider()->video(VideoRef::youtube('aqz-KE-bpKQ'));

        self::assertSame(['GET '.self::API.'videos?part=snippet%2CcontentDetails%2Cstatistics%2Cstatus%2CliveStreamingDetails&id=aqz-KE-bpKQ&key='.self::KEY], $this->requests);
        self::assertNotNull($video);
        self::assertSame(['Big Buck Bunny 60fps 4K', 635, '2014-11-10T14:05:55+00:00', 1234567, 'en', 'public', true, ['animation', 'open movie']], [$video->title, $video->duration, $video->published?->format(\DATE_ATOM), $video->views, $video->language, $video->privacy, $video->embeddable, $video->tags]);
        self::assertSame(['https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'https://www.youtube.com/embed/aqz-KE-bpKQ', 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ'], [$video->url, $video->embedUrl, $video->privateEmbedUrl]);
        self::assertSame([self::CHANNEL, 'Example Channel'], [$video->channel?->id, $video->channel?->title]);
        self::assertSame([120, 320, 480, 1280], array_map(static fn ($image) => $image->width, $video->thumbnails));
        self::assertSame('https://i.ytimg.com/vi/aqz-KE-bpKQ/maxresdefault.jpg', $video->thumbnail()?->url);
        self::assertNull($video->stream, 'an upload, not a broadcast');
    }

    public function testSeveralVideosInOneCallInTheOrderAsked(): void
    {
        $videos = $this->provider(['language' => 'fr'])->videos([VideoRef::youtube('aqz-KE-bpKQ'), VideoRef::youtube('deleted0000'), VideoRef::youtube('vKQi3bBA1y8')]);

        self::assertSame(['GET '.self::API.'videos?part=snippet%2CcontentDetails%2Cstatistics%2Cstatus%2CliveStreamingDetails&id=aqz-KE-bpKQ%2Cdeleted0000%2CvKQi3bBA1y8&hl=fr&key='.self::KEY], $this->requests);
        self::assertSame([['aqz-KE-bpKQ', 635], ['vKQi3bBA1y8', 3720]], array_map(static fn (Video $v) => [$v->ref->id, $v->duration], $videos), 'the unknown one left out');
    }

    public function testAVideoOfAnotherPlatformIsRefused(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('YouTube does not host Vimeo videos.');
        $this->provider()->video(VideoRef::vimeo(76979871));
    }

    public function testASearchAnswersShortRecordsAndTheNextPage(): void
    {
        $page = $this->provider()->searchVideos(new VideoQuery('big buck bunny', self::CHANNEL, 'en', 2));

        self::assertSame(['GET '.self::API.'search?part=snippet&type=video&q=big%20buck%20bunny&channelId='.self::CHANNEL.'&relevanceLanguage=en&maxResults=2&key='.self::KEY], $this->requests);
        self::assertSame(['aqz-KE-bpKQ', 'vKQi3bBA1y8'], array_map(static fn (Video $v) => $v->ref->id, $page->items));
        self::assertSame(['CAIQAA', 2], [$page->next, $page->total]);
        self::assertNull($page->items[0]->duration, 'a search answers the snippet only');
        self::assertSame('https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ', $page->items[0]->embed(true));

        $this->provider()->searchVideos(new VideoQuery('big buck bunny', page: 'CAIQAA'));
        self::assertSame('GET '.self::API.'search?part=snippet&type=video&q=big%20buck%20bunny&maxResults=10&pageToken=CAIQAA&key='.self::KEY, $this->request(1));
    }

    public function testAChannelByItsHandleAndItsUploads(): void
    {
        $provider = $this->provider();
        $channel = $provider->channel('@example');

        self::assertNotNull($channel);
        self::assertSame([self::CHANNEL, 'Example Channel', '@example', 'https://www.youtube.com/@example', 'NL', 1230000, 321, '2008-02-27T16:54:02+00:00'], [$channel->id, $channel->title, $channel->handle, $channel->url, $channel->country, $channel->subscribers, $channel->videos, $channel->published?->format(\DATE_ATOM)]);
        self::assertSame(800, $channel->thumbnails[2]->width);

        $page = $provider->channelVideos('@example', null, 3);
        self::assertSame([
            'GET '.self::API.'channels?part=snippet%2CcontentDetails%2Cstatistics&forHandle=@example&key='.self::KEY,
            'GET '.self::API.'playlistItems?part=snippet%2CcontentDetails&playlistId=UUexampleChannel00000001&maxResults=3&key='.self::KEY,
            'GET '.self::API.'videos?part=snippet%2CcontentDetails%2Cstatistics%2Cstatus%2CliveStreamingDetails&id=vKQi3bBA1y8%2Cdeleted0000%2Caqz-KE-bpKQ&key='.self::KEY,
        ], $this->requests, 'the channel read once, its uploads playlist, then the whole records');
        self::assertSame([[0, 'vKQi3bBA1y8', 3720], [2, 'aqz-KE-bpKQ', 635]], array_map(static fn (Video $v) => [$v->position, $v->ref->id, $v->duration], $page->items), 'a deleted video left out');
        self::assertSame('EAAaBlBUOkNESQ', $page->next);

        self::assertNull($provider->channel('@nobody'));
        self::assertSame([], $provider->channelVideos('@nobody')->items);
    }

    public function testAChannelByItsIdentifier(): void
    {
        $this->provider()->channel(self::CHANNEL);

        self::assertSame(['GET '.self::API.'channels?part=snippet%2CcontentDetails%2Cstatistics&id='.self::CHANNEL.'&key='.self::KEY], $this->requests);
    }

    public function testPlaylistsAndAnUnknownOne(): void
    {
        $provider = $this->provider();
        $page = $provider->playlists(self::CHANNEL, null, 2);

        self::assertSame(['GET '.self::API.'playlists?part=snippet%2CcontentDetails&channelId='.self::CHANNEL.'&maxResults=2&key='.self::KEY], $this->requests, 'a channel id needs no channels.list');
        self::assertSame([['Open movies', 12], ['Talks', 3]], array_map(static fn (Playlist $p) => [$p->title, $p->count], $page->items));
        self::assertSame('https://www.youtube.com/playlist?list=PLexample0000000000000000000000001', $page->items[0]->url);
        self::assertSame('CAIQAA', $page->next);

        self::assertSame('Open movies', $provider->playlist('PLexample0000000000000000000000001')?->title);
        self::assertSame('GET '.self::API.'playlists?part=snippet%2CcontentDetails&id=PLexample0000000000000000000000001&key='.self::KEY, $this->request(1));

        $none = $provider->playlistVideos('PLunknown');
        self::assertSame([], $none->items, 'playlistNotFound (404) is an empty page');
        self::assertNull($none->next);
    }

    public function testWhatAChannelBroadcastsLive(): void
    {
        $stream = $this->provider()->live(self::CHANNEL);

        self::assertSame([
            'GET '.self::API.'search?part=snippet&channelId='.self::CHANNEL.'&eventType=live&type=video&maxResults=1&key='.self::KEY,
            'GET '.self::API.'videos?part=snippet%2CcontentDetails%2Cstatistics%2Cstatus%2CliveStreamingDetails&id=liveStream1&key='.self::KEY,
        ], $this->requests);
        self::assertNotNull($stream);
        self::assertSame([LiveState::Live, 'Live from the studio', 321, '2026-11-09T18:00:05+00:00', '2026-11-09T18:00:00+00:00', 'youtube:liveStream1'], [$stream->state, $stream->title, $stream->viewers, $stream->started?->format(\DATE_ATOM), $stream->scheduled?->format(\DATE_ATOM), (string) $stream->video]);
        self::assertSame(['https://www.youtube.com/watch?v=liveStream1', 'https://www.youtube-nocookie.com/embed/liveStream1'], [$stream->url, $stream->embed(true)]);
        self::assertSame(1280, $stream->thumbnail?->width);
    }

    public function testAChannelOffAirIsNull(): void
    {
        self::assertNull($this->provider()->live('UCquietChannel0000000001'));
        self::assertCount(1, $this->requests, 'no live video: no videos.list');
    }

    public function testTheScheduleSoonestFirst(): void
    {
        $schedule = $this->provider()->schedule('@example', 5);

        self::assertSame([
            'GET '.self::API.'channels?part=snippet%2CcontentDetails%2Cstatistics&forHandle=@example&key='.self::KEY,
            'GET '.self::API.'search?part=snippet&channelId='.self::CHANNEL.'&eventType=upcoming&type=video&maxResults=5&key='.self::KEY,
            'GET '.self::API.'videos?part=snippet%2CcontentDetails%2Cstatistics%2Cstatus%2CliveStreamingDetails&id=upcomingB02%2CupcomingA01&key='.self::KEY,
        ], $this->requests);
        self::assertSame([['Tomorrow', '2026-11-10T18:00:00+00:00', '2026-11-10T19:30:00+00:00'], ['Next week', '2026-11-16T18:00:00+00:00', null]], array_map(static fn (Stream $s) => [$s->title, $s->scheduled?->format(\DATE_ATOM), $s->scheduledEnd?->format(\DATE_ATOM)], $schedule));
        self::assertSame(LiveState::Upcoming, $schedule[0]->state);
    }

    public function testQuotaAndKeyErrorsAreProviderErrorsWithoutTheKey(): void
    {
        foreach (['quota' => 'YouTube answered 403 for GET /youtube/v3/videos.', 'badkey' => 'YouTube answered 400 for GET /youtube/v3/videos.'] as $case => $start) {
            try {
                $this->provider(['key' => $case])->video(VideoRef::youtube('aqz-KE-bpKQ'));
                self::fail($case);
            } catch (ProviderException $e) {
                self::assertStringStartsWith($start, $e->getMessage());
                self::assertStringNotContainsString('key='.$case, $e->getMessage());
            }
        }
    }

    public function testAKeyIsRequiredAndTheCapabilitiesSaid(): void
    {
        $capabilities = $this->provider()->capabilities();
        self::assertSame(['video', 'live'], $capabilities->families());
        self::assertSame(['video', 'videoSearch', 'channels', 'playlists', 'live'], $capabilities->contracts());
        self::assertTrue($capabilities->schedule);
        self::assertFalse($capabilities->keyless);
        self::assertSame('youtube', (new YoutubeFactory(new MockHttpClient()))->getName());

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "youtube" provider needs: key.');
        (new YoutubeFactory(new MockHttpClient()))->create();
    }

    private function request(int $index): ?string
    {
        return $this->requests[$index] ?? null;
    }

    /** @param array<string, mixed> $options */
    private function provider(array $options = []): YoutubeProvider
    {
        $f = __DIR__.'/Fixtures/';
        $routes = [
            '~key=quota$~' => [403, 'error-quota.json'],
            '~key=badkey$~' => [400, 'error-key.json'],
            '~/videos\?.*id=aqz-KE-bpKQ&~' => [200, 'videos-bunny.json'],
            '~/videos\?.*id=(aqz-KE-bpKQ%2Cdeleted0000%2CvKQi3bBA1y8|vKQi3bBA1y8%2Cdeleted0000%2Caqz-KE-bpKQ)&~' => [200, 'videos-two.json'],
            '~/videos\?.*id=liveStream1&~' => [200, 'videos-live.json'],
            '~/videos\?.*id=upcomingB02%2CupcomingA01&~' => [200, 'videos-upcoming.json'],
            '~/search\?.*eventType=live&~' => [200, 'search-live.json'],
            '~/search\?.*eventType=upcoming&~' => [200, 'search-upcoming.json'],
            '~/search\?.*q=~' => [200, 'search-bunny.json'],
            '~/channels\?.*(forHandle=@example|id=UCexampleChannel00000001)&~' => [200, 'channels-example.json'],
            '~/channels\?~' => [200, 'channels-none.json'],
            '~/playlistItems\?.*playlistId=UUexampleChannel00000001&~' => [200, 'playlistItems-uploads.json'],
            '~/playlistItems\?~' => [404, 'playlist-not-found.json'],
            '~/playlists\?.*channelId=~' => [200, 'playlists-channel.json'],
            '~/playlists\?.*id=PLexample0000000000000000000000001&~' => [200, 'playlists-one.json'],
        ];
        $http = new MockHttpClient(function (string $method, string $url) use ($routes, $f): MockResponse {
            $this->requests[] = $method.' '.$url;
            if (str_contains($url, 'channelId=UCquietChannel0000000001')) {
                return new MockResponse((string) file_get_contents($f.'search-none.json'));
            }
            foreach ($routes as $pattern => [$status, $file]) {
                if (preg_match($pattern, $url)) {
                    return new MockResponse((string) file_get_contents($f.$file), ['http_code' => $status]);
                }
            }

            return new MockResponse('{"error": {"code": 404, "message": "not recorded"}}', ['http_code' => 404]);
        });
        $provider = (new YoutubeFactory($http))->create($options + ['key' => self::KEY]);
        self::assertInstanceOf(YoutubeProvider::class, $provider);

        return $provider;
    }
}
