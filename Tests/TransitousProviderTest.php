<?php

namespace Omnitravel\Transitous\Tests;

use Omnitravel\Exception\InvalidConfigException;
use Omnitravel\Exception\NotSupportedException;
use Omnitravel\Exception\ProviderException;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\JourneyQuery;
use Omnitravel\Model\Line;
use Omnitravel\Model\Mode;
use Omnitravel\Model\Place;
use Omnitravel\Model\Stop;
use Omnitravel\Testing\RecordedHttpClient;
use Omnitravel\Transitous\Modes;
use Omnitravel\Transitous\TransitousFactory;
use Omnitravel\Transitous\TransitousProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transitous' answers recorded on 2026-11-09 at 12:34 UTC (geocode, stoptimes,
 * plan: trimmed to the fields read); /api/v6/stop and /api/v6/map/stops are
 * written from MOTIS's OpenAPI (v6) with the stops and the route those answers
 * carried, nothing invented beside them.
 */
final class TransitousProviderTest extends TestCase
{
    private const UA = 'omnitravel-tests/1.0 (+https://github.com/glitchr-omnisense/omnitravel; tests@example.org)';
    private const IRIGO = 'fr-angers-loire-metropole-reseau-irigo-gtfs-gtfs-rt-siri_';

    public function testAStopsDeparturesWithTheirDelaysAndCancellations(): void
    {
        $http = self::http();
        $departures = self::provider($http)->departures(new DepartureQuery(self::IRIGO.'1RALL', new \DateTimeImmutable('2026-11-09T12:34:00Z'), 6));

        self::assertCount(6, $departures);
        $first = $departures[0];
        self::assertSame(['C', 'Belle-Beille Campus', Mode::Tram, '#379E32', '#FFFFFF'], [$first->line?->name, $first->headsign, $first->mode(), $first->line?->color, $first->line?->textColor]);
        self::assertSame(['2026-11-09T12:33:00+00:00', '2026-11-09T12:35:00+00:00', 120], [$first->scheduled->format(\DATE_ATOM), $first->expected?->format(\DATE_ATOM), $first->delay()]);
        self::assertSame(self::IRIGO.'2RALL', $first->stop->id, 'the quay the tram leaves from');
        self::assertSame('IRIGO', $first->line?->operator?->name);
        self::assertSame(['Roseraie - Belle-Beille Campus', 'transitous'], [$first->line->longName, $first->source]);
        self::assertNull($departures[3]->expected, 'no real-time data: no expected time');
        self::assertFalse($departures[3]->realtime);
        self::assertTrue($departures[5]->cancelled);
        self::assertSame([false, false, false, false, false, true], array_map(static fn (Departure $d) => $d->cancelled, $departures));

        self::assertCount(1, $http->requests);
        self::assertSame('https://api.transitous.org/api/v6/stoptimes', $http->requests[0]['url']);
        self::assertSame(['stopId' => self::IRIGO.'1RALL', 'time' => '2026-11-09T12:34:00Z', 'n' => 6, 'language' => 'fr'], $http->requests[0]['options']['query'] ?? null);
        self::assertSame(self::UA, $http->header('User-Agent'));
    }

    public function testArrivalsAndModesAreAskedAsMotisNamesThem(): void
    {
        $http = self::http();
        self::provider($http)->departures(new DepartureQuery(self::IRIGO.'1RALL', limit: 3, window: 1800, modes: [Mode::Tram, Mode::RegionalRail], arrivals: true, language: 'en'));

        self::assertSame(['stopId' => self::IRIGO.'1RALL', 'n' => 3, 'window' => 1800, 'arriveBy' => 'true', 'mode' => 'TRAM,REGIONAL_RAIL,REGIONAL_FAST_RAIL', 'language' => 'en'], $http->requests[0]['options']['query'] ?? null);
    }

    public function testALineFilterIsAppliedToTheAnswer(): void
    {
        $departures = self::provider(self::http())->departures(new DepartureQuery(self::IRIGO.'1RALL', lines: [self::IRIGO.'A']));

        self::assertSame([], $departures, 'only line C called in the recorded answer');
    }

    public function testJourneysFromAToBWithTheirLegs(): void
    {
        $http = self::http();
        $journeys = self::provider($http)->journeys(new JourneyQuery(Place::at(47.4712, -0.5518, 'Home'), Place::at(47.4608, -0.5306, 'Stadium'), new \DateTimeImmutable('2026-11-09T13:34:00+01:00'), limit: 3));

        self::assertCount(4, $journeys, 'three itineraries, then the direct walk');
        $first = $journeys[0];
        self::assertSame(['2026-11-09T12:38:00+00:00', '2026-11-09T12:53:00+00:00', 900, 0], [$first->departure()->format(\DATE_ATOM), $first->arrival()->format(\DATE_ATOM), $first->duration(), $first->transfers()]);
        self::assertSame([Mode::Walk, Mode::Bus, Mode::Walk], array_map(static fn ($leg) => $leg->mode, $first->legs));
        self::assertSame(['Home', 'Lorraine', 'Stade Raymond Kopa', 'Stadium'], [$first->legs[0]->from->name, $first->legs[1]->from->name, $first->legs[1]->to->name, $first->legs[2]->to->name]);
        $bus = $first->legs[1];
        self::assertSame(['8', 'SORGES', true], [$bus->line?->name, $bus->headsign, $bus->realtime]);
        self::assertSame(self::IRIGO.'LOHUIT', $bus->from->stop?->id);
        self::assertCount(4, $bus->stops);
        self::assertSame(6, $bus->precision);
        self::assertNotEmpty($bus->path());
        self::assertLessThan(30, $bus->path()[0]->distanceTo($bus->from->coordinates() ?? new Coordinates(0, 0)), 'the path starts at the stop');
        self::assertSame(453.0, $first->legs[0]->distance);
        self::assertSame(453.0 + 93.0, $first->walking());

        $late = $journeys[2]->legs[1];
        self::assertSame(['7', 180], [$late->line?->name, $late->delay()], 'scheduled 12:52, leaves 12:55');
        self::assertSame([Mode::Walk], array_map(static fn ($leg) => $leg->mode, $journeys[3]->legs));
        self::assertSame(1740, $journeys[3]->duration(), 'door to door from its times, rounded to the minute (MOTIS says 1776 s)');

        self::assertSame('https://api.transitous.org/api/v6/plan', $http->requests[0]['url']);
        self::assertSame(['fromPlace' => '47.471200,-0.551800', 'toPlace' => '47.460800,-0.530600', 'time' => '2026-11-09T12:34:00Z', 'numItineraries' => 3, 'language' => 'fr'], $http->requests[0]['options']['query'] ?? null);
    }

    public function testAPlanAsksTransitousStopsByIdAndPassesItsOptions(): void
    {
        $http = self::http();
        $stop = new Stop(self::IRIGO.'1RALL', 'Ralliement', 'transitous', new Coordinates(47.47113, -0.551664));
        $elsewhere = new Stop('1RALL', 'Ralliement', 'angers', new Coordinates(47.47113, -0.551664));
        self::provider($http)->journeys(new JourneyQuery(Place::ofStop($stop), Place::ofStop($elsewhere), arriveBy: true, modes: [Mode::Bus], maxTransfers: 1, wheelchair: true));

        self::assertSame(['fromPlace' => self::IRIGO.'1RALL', 'toPlace' => '47.471130,-0.551664', 'arriveBy' => 'true', 'maxTransfers' => 1, 'transitModes' => 'BUS', 'numItineraries' => 5, 'pedestrianProfile' => 'WHEELCHAIR', 'language' => 'fr'], $http->requests[0]['options']['query'] ?? null);
    }

    public function testAStopWithItsLines(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        $stop = $provider->stop(self::IRIGO.'1RALL');

        self::assertNotNull($stop);
        self::assertSame(['Ralliement', '1RALL', null, 'Europe/Paris', [Mode::Tram]], [$stop->name, $stop->code, $stop->parentId, $stop->timezone, $stop->modes]);
        self::assertSame(['C'], array_map(static fn (Line $l) => $l->name, $stop->lines));
        self::assertSame(['#379E32', '#FFFFFF', Mode::Tram, 'https://www.irigo.fr/'], [$stop->lines[0]->color, $stop->lines[0]->textColor, $stop->lines[0]->mode, $stop->lines[0]->operator?->url]);
        self::assertSame(['C'], array_map(static fn (Line $l) => $l->name, $provider->stopLines(self::IRIGO.'1RALL')));
        self::assertNull($provider->stop('nowhere'));
        self::assertSame(['stopId' => self::IRIGO.'1RALL', 'language' => 'fr'], $http->requests[0]['options']['query'] ?? null);
    }

    public function testTheStopsNearAPointNearestFirst(): void
    {
        $http = self::http();
        $here = new Coordinates(47.4705, -0.5505);
        $near = self::provider($http)->nearby($here, 400, 3);

        self::assertSame([self::IRIGO.'1RALL', self::IRIGO.'2RALL', self::IRIGO.'FO-LOR-R'], array_map(static fn (Stop $s) => $s->id, $near));
        self::assertEqualsWithDelta(112, $near[0]->distance, 2);
        self::assertSame([Mode::Bus], $near[2]->modes);
        self::assertCount(5, self::provider($http)->nearby($here, 300), 'Saumuroise, Coubertin and the stadium are farther');
        $query = $http->requests[0]['options']['query'] ?? [];
        self::assertIsArray($query);
        self::assertSame(['min', 'max', 'language'], array_keys($query));
        self::assertSame('https://api.transitous.org/api/v6/map/stops', $http->requests[0]['url']);
        self::assertSame('47.466903,-0.555822', $query['min']);
        self::assertSame('47.474097,-0.545178', $query['max']);
    }

    public function testStopsByNameOnceEach(): void
    {
        $http = self::http();
        $found = self::provider($http)->searchStops('Ralliement', 5, new Coordinates(47.4712, -0.5518));

        self::assertSame([self::IRIGO.'1RALL', 'fi-fintraffic_365967', 'fi-fintraffic_324617'], array_map(static fn (Stop $s) => $s->id, $found));
        self::assertSame([Mode::Tram], $found[0]->modes);
        self::assertEqualsWithDelta(14, $found[0]->distance, 2);
        self::assertSame(['text' => 'Ralliement', 'type' => 'STOP', 'place' => '47.471200,-0.551800', 'numResults' => 5, 'language' => 'fr'], $http->requests[0]['options']['query'] ?? null);
        self::assertSame([], self::provider($http)->searchStops('  '));
        self::assertCount(1, $http->requests, 'an empty search asks nothing');
    }

    public function testWhatTransitousKeepsNoListOfIsNotSupported(): void
    {
        $provider = self::provider(self::http());
        self::assertFalse($provider->capabilities()->lines);
        foreach ([static fn () => $provider->lines(), static fn () => $provider->line('A'), static fn () => $provider->lineStops('A'), static fn () => $provider->operators()] as $call) {
            try {
                $call();
                self::fail('no exception');
            } catch (NotSupportedException $e) {
                self::assertStringStartsWith('Transitous does not ', $e->getMessage());
            }
        }
    }

    public function testCapabilitiesSayThePolicyAndTheAttribution(): void
    {
        $capabilities = self::provider(self::http())->capabilities();

        self::assertSame(['timetables', 'itineraries', 'network'], $capabilities->contracts());
        self::assertTrue($capabilities->keyless);
        self::assertTrue($capabilities->realtime);
        self::assertSame([], $capabilities->countries);
        self::assertSame(TransitousProvider::ATTRIBUTION, $capabilities->attribution);
        self::assertContains('https://transitous.org/api/', $capabilities->terms);
    }

    public function testAnErrorAnsweredIsAProviderException(): void
    {
        $http = RecordedHttpClient::routes(['~/api/v6/stoptimes~' => [422, '{"error": "stop not found"}']]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Transitous answered 422 for GET /api/v6/stoptimes. {"error": "stop not found"}');
        self::provider($http)->departures(new DepartureQuery('nowhere'));
    }

    public function testAUserAgentWithoutAContactIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Transitous asks every request to name the application, its version and a contact');
        (new TransitousFactory(self::http()))->create(['user_agent' => 'MySite/1.0']);
    }

    public function testTheUserAgentIsRequiredAndTheBaseUriMayChange(): void
    {
        try {
            (new TransitousFactory(self::http()))->create();
            self::fail('no exception');
        } catch (InvalidConfigException $e) {
            self::assertSame('The "transitous" provider needs: user_agent.', $e->getMessage());
        }

        $http = self::http();
        self::provider($http, ['base_uri' => 'https://motis.example.org/'])->stop(self::IRIGO.'1RALL');
        self::assertSame('https://motis.example.org/api/v6/stop', $http->requests[0]['url']);
    }

    public function testModesBothWays(): void
    {
        self::assertSame([Mode::Metro, Mode::Suburban, Mode::Suburban, Mode::AerialLift, Mode::Other], array_map(Modes::from(...), ['SUBWAY', 'SUBURBAN', 'METRO', 'AREAL_LIFT', 'DEBUG_BUS_ROUTE']));
        self::assertSame('BUS,CABLE_CAR,AIRPLANE', Modes::toQuery([Mode::Bus, Mode::Trolleybus, Mode::CableCar, Mode::Air]));
        self::assertNull(Modes::toQuery([]));
    }

    /** @param array<string, mixed> $options */
    private static function provider(HttpClientInterface $http, array $options = []): TransitousProvider
    {
        $provider = (new TransitousFactory($http))->create($options + ['user_agent' => self::UA, 'language' => 'fr']);
        self::assertInstanceOf(TransitousProvider::class, $provider);

        return $provider;
    }

    private static function http(): RecordedHttpClient
    {
        $fixtures = __DIR__.'/Fixtures';

        return RecordedHttpClient::routes([
            '~^GET https://[^/]+/api/v6/stoptimes\?stopId=[^&]*1RALL~' => [200, '@'.$fixtures.'/stoptimes-ralliement.json'],
            '~^GET https://[^/]+/api/v6/plan\?~' => [200, '@'.$fixtures.'/plan-angers.json'],
            '~^GET https://[^/]+/api/v6/stop\?stopId=[^&]*1RALL~' => [200, '@'.$fixtures.'/stop-ralliement.json'],
            '~^GET https://[^/]+/api/v6/map/stops\?~' => [200, '@'.$fixtures.'/map-stops-angers.json'],
            '~^GET https://[^/]+/api/v1/geocode\?text=Ralliement~' => [200, '@'.$fixtures.'/geocode-ralliement.json'],
        ]);
    }
}
