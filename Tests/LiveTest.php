<?php

namespace Omnitravel\Transitous\Tests;

use Omnitravel\Model\Coordinates;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\JourneyQuery;
use Omnitravel\Model\Place;
use Omnitravel\Transitous\TransitousFactory;
use Omnitravel\Transitous\TransitousProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Transitous itself, within its policy (a User-Agent naming the application
 * and a contact, three requests one after the other): a stop by name in
 * Angers, its departures, a journey across the city. `docker compose run
 * --rm omnitravel live`; never in the default run.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    private const UA = 'omnitravel-tests/1.0 (+https://github.com/glitchr-omnisense/omnitravel; marco.meyer@glitchr.io)';

    public function testAStopItsDeparturesAndAJourney(): void
    {
        $provider = (new TransitousFactory(HttpClient::create(['timeout' => 30])))->create(['user_agent' => self::UA, 'language' => 'fr']);
        self::assertInstanceOf(TransitousProvider::class, $provider);

        $stop = $provider->searchStops('Ralliement', 3, new Coordinates(47.4712, -0.5518))[0] ?? null;
        self::assertNotNull($stop);
        self::assertStringEndsWith('_1RALL', $stop->id);

        $departures = $provider->departures(new DepartureQuery($stop->id, limit: 4));
        self::assertNotEmpty($departures);
        self::assertSame('IRIGO', $departures[0]->line?->operator?->name);

        $journeys = $provider->journeys(new JourneyQuery(Place::at(47.4712, -0.5518), Place::at(47.4608, -0.5306), limit: 2));
        self::assertNotEmpty($journeys);
        self::assertGreaterThan(0, $journeys[0]->duration());
    }
}
