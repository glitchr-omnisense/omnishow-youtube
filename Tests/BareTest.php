<?php

namespace Omnishow\Youtube\Tests;

use Omnishow\Testing\BareTestCase;
use Omnishow\Youtube\YoutubeProvider;

/** omnishow/youtube's classes load with omnisense/omnishow and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return YoutubeProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
