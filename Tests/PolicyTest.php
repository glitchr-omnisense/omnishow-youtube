<?php

namespace Omnishow\Youtube\Tests;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnishow\Exception\OmnishowException;
use Omnishow\Exception\UsageLimitReachedException;
use Omnishow\Model\VideoRef;
use Omnishow\Registry;
use Omnishow\Testing\RecordedHttpClient;
use Omnishow\Youtube\YoutubeFactory;
use Omnishow\Youtube\YoutubeProvider;
use PHPUnit\Framework\TestCase;

/**
 * YoutubeProvider's usage policy (policy.json) through omnishow's registry:
 * as declared, a site's limit counted with the call over it never made,
 * a 429 and its Retry-After holding every next call, a looser setting
 * refused. No network: recorded answers only.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['key' => 'not-a-real-youtube-key'];

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = (new Registry([new YoutubeFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'youtube', 'options' => self::OPTIONS]]))->policy('films');

        self::assertSame('youtube', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(YoutubeProvider::POLICY);
        self::assertSame(['100/day/application/live+schedule+searchVideos', '10000/day/application/*/units/channelVideos=3,live=2,playlistVideos=2,playlists=2,schedule=2,searchVideos=0'], array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertCount(2, $policy->limitsFor('searchVideos'), 'a search counts in both');
        self::assertCount(1, $policy->limitsFor('video'));
        self::assertSame(2592000, $policy->rights->retention?->maxAge, 'data not authorised by its owner kept 30 days at most');
        self::assertNull($policy->rights->caching->maxTtl, 'the 30 days are the retention\'s, which bounds a cached answer too');
    }

    public function testEachOperationCostsTheUnitsOfTheMethodsItMakes(): void
    {
        $registry = new Registry([new YoutubeFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'youtube', 'options' => self::OPTIONS]]);
        $units = ($registry->policy('films')->rateLimits ?? [])[1];

        self::assertSame('units', $units->unit->value);
        self::assertSame(3, $units->cost('channelVideos'), 'channels.list, playlistItems.list, videos.list');
        self::assertSame(2, $units->cost('live'), 'channels.list and videos.list; search.list has its own bucket');
        self::assertSame(0, $units->cost('searchVideos'));
        self::assertSame(1, $units->cost('video'));
        self::assertSame(3333, $registry->guard('films')->remaining('channelVideos'));
        self::assertSame(100, $registry->guard('films')->remaining('searchVideos'), 'only the search bucket counts a search');
    }

    public function testVideosAreCountedPerRequestOf50(): void
    {
        $http = RecordedHttpClient::routes(['~/videos~' => [200, '{"items":[]}']]);
        $registry = new Registry([new YoutubeFactory($http)], ['films' => ['factory' => 'youtube', 'options' => self::OPTIONS]]);
        $refs = array_map(static fn (int $i) => VideoRef::youtube(\sprintf('video%06d', $i)), range(1, 120));

        self::assertSame([], $registry->video('films')->videos($refs));
        self::assertCount(3, $http->requests, '120 videos, 3 requests of videos.list');
        self::assertSame(9997, $registry->guard('films')->remaining('video'), '3 units spent');
        self::assertSame(['units' => 1], (new YoutubeProvider(new \Omnishow\Http\JsonClient($http, 'YouTube'), 'k'))->usage('videos', [[VideoRef::youtube('a'), VideoRef::youtube('a')]]), 'the same video once');
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = RecordedHttpClient::routes([]);
        $registry = new Registry([new YoutubeFactory($http)], ['films' => ['factory' => 'youtube', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['video']]]]]]);

        try {
            $registry->video('films')->video(VideoRef::youtube('aqz-KE-bpKQ'));
        } catch (UsageLimitReachedException $e) {
            throw $e;
        } catch (OmnishowException) {
            // Nothing recorded: the provider's own answer to that, counted all the same.
        }
        $made = \count($http->requests);
        self::assertGreaterThan(0, $made);
        try {
            $registry->video('films')->video(VideoRef::youtube('aqz-KE-bpKQ'));
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnishowException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/day/application/video', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~.~' => [429, '{"error":"too many requests"}', ['retry-after' => ['120']]]]);
        $registry = new Registry([new YoutubeFactory($http)], ['films' => ['factory' => 'youtube', 'options' => self::OPTIONS]]);

        try {
            $registry->video('films')->video(VideoRef::youtube('aqz-KE-bpKQ'));
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('youtube answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            $registry->video('films')->video(VideoRef::youtube('aqz-KE-bpKQ'));
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the wait held the next request back');
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = new Registry([new YoutubeFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'youtube', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 1000, 'window' => 'day', 'operations' => ['searchVideos', 'live', 'schedule']]]]]]);

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('100 a day');
        $registry->video('films');
    }
}
