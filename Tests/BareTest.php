<?php

namespace Omnitravel\Transitous\Tests;

use Omnitravel\Testing\BareTestCase;
use Omnitravel\Transitous\TransitousProvider;

/** omnitravel/transitous' classes load with omnisense/omnitravel and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return TransitousProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
