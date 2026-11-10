<?php

namespace Omnishow\Youtube;

use Omnishow\Config;
use Omnishow\Http\JsonClient;
use Omnishow\ProviderFactory;
use Omnishow\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "youtube": options key (required: a YouTube Data API v3 key), language
 * (titles and descriptions in this language where the owner translated
 * them: videos.list's hl), api ("https://www.googleapis.com/youtube/v3/").
 */
final class YoutubeFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishow.factory_name' => 'youtube',
            'omnishow.factory_title' => 'YouTube',
            'omnishow.required_options' => ['key'],
            'api' => 'https://www.googleapis.com/youtube/v3/',
            'language' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new YoutubeProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'YouTube', ['Accept' => 'application/json'], $c->backoff()), (string) $c->string('key'), (string) $c->string('api'), $c->string('language'));
    }
}
