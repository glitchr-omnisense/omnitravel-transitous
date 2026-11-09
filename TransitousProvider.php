<?php

namespace Omnitravel\Transitous;

use Omnitravel\Capabilities;
use Omnitravel\Exception\NotSupportedException;
use Omnitravel\Http\Data;
use Omnitravel\Http\JsonClient;
use Omnitravel\ItinerariesInterface;
use Omnitravel\Model\BoundingBox;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\Journey;
use Omnitravel\Model\JourneyQuery;
use Omnitravel\Model\Leg;
use Omnitravel\Model\Line;
use Omnitravel\Model\Mode;
use Omnitravel\Model\Operator;
use Omnitravel\Model\Place;
use Omnitravel\Model\Stop;
use Omnitravel\Model\StopKind;
use Omnitravel\NetworkInterface;
use Omnitravel\TimetablesInterface;

/**
 * Transitous, the community-run MOTIS 2 instance over the open timetables
 * it gathers worldwide (https://transitous.org), with no key: journeys
 * (/api/v6/plan), a stop's departures and arrivals in real time
 * (/api/v6/stoptimes), a stop and the lines calling there (/api/v6/stop),
 * the stops in an area (/api/v6/map/stops) and by name (/api/v1/geocode).
 * Stop identifiers are MOTIS's: the feed's own prefixed with its dataset
 * tag ("fr-angers-..._1RALL"). Transitous keeps no list of a network's
 * lines: lines(), line(), lineStops() and operators() are not supported.
 */
final class TransitousProvider implements TimetablesInterface, ItinerariesInterface, NetworkInterface
{
    public const ATTRIBUTION = 'Transitous (https://transitous.org/sources/) and OpenStreetMap contributors';

    public function __construct(
        private readonly JsonClient $json,
        private readonly string $baseUri = 'https://api.transitous.org',
        private readonly ?string $language = null,
    ) {
    }

    public function getName(): string
    {
        return 'transitous';
    }

    public function getTitle(): string
    {
        return 'Transitous';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            timetables: true,
            arrivals: true,
            realtime: true,
            itineraries: true,
            network: true,
            nearby: true,
            stopSearch: true,
            platforms: true,
            modes: [Mode::Tram, Mode::Metro, Mode::Rail, Mode::Suburban, Mode::RegionalRail, Mode::HighSpeedRail, Mode::LongDistanceRail, Mode::NightRail, Mode::Bus, Mode::Coach, Mode::Ferry, Mode::CableCar, Mode::AerialLift, Mode::Funicular, Mode::Air, Mode::OnDemand, Mode::Walk, Mode::Bike, Mode::Car],
            attribution: self::ATTRIBUTION,
            licence: 'ODbL-1.0',
            terms: ['https://transitous.org/api/', 'https://transitous.org/sources/', 'https://www.openstreetmap.org/copyright'],
            keyless: true,
        );
    }

    public function departures(DepartureQuery $query): array
    {
        $data = $this->json->get($this->baseUri.'/api/v6/stoptimes', [
            'stopId' => $query->stop,
            'time' => null === $query->from ? null : self::time($query->from),
            'n' => $query->limit,
            'window' => $query->window,
            'arriveBy' => $query->arrivals ? 'true' : null,
            'mode' => Modes::toQuery($query->modes),
            'language' => $query->language ?? $this->language,
        ]);
        if (null === $data) {
            return [];
        }

        $departures = [];
        foreach ($data->list('stopTimes') as $time) {
            $place = $time->data('place');
            $scheduled = $place->dateTime($query->arrivals ? 'scheduledArrival' : 'scheduledDeparture') ?? $place->dateTime($query->arrivals ? 'arrival' : 'departure');
            if (null === $scheduled) {
                continue;
            }
            $realtime = true === $time->bool('realTime');
            $line = $this->lineOf($time);
            $track = $place->string('track');
            $scheduledTrack = $place->string('scheduledTrack');
            $departure = new Departure(
                $this->stopOf($place),
                $line,
                $time->string('headsign') ?? $time->string('tripTo.name') ?? '',
                $scheduled,
                $this->getName(),
                $realtime ? $place->dateTime($query->arrivals ? 'arrival' : 'departure') : null,
                $realtime,
                true === $time->bool('cancelled') || true === $time->bool('tripCancelled') || true === $place->bool('cancelled'),
                $track ?? $scheduledTrack,
                null !== $track && $track !== $scheduledTrack ? $scheduledTrack : null,
                tripId: $time->string('tripId'),
                number: $time->string('tripShortName'),
                mode: Modes::from($time->string('mode')),
                origin: $query->arrivals ? $time->string('tripFrom.name') : null,
                arrival: $query->arrivals,
            );
            if ($query->accepts($departure)) {
                $departures[] = $departure;
            }
        }

        return Departure::sorted($departures);
    }

    /**
     * The journeys MOTIS finds, at most the query's limit, then its direct
     * ones (on foot by default) after them, outside the limit: a walk shorter
     * than any ride is not lost to the count.
     */
    public function journeys(JourneyQuery $query): array
    {
        $data = $this->json->get($this->baseUri.'/api/v6/plan', [
            'fromPlace' => $this->placeParameter($query->from),
            'toPlace' => $this->placeParameter($query->to),
            'time' => null === $query->time ? null : self::time($query->time),
            'arriveBy' => $query->arriveBy ? 'true' : null,
            'maxTransfers' => $query->maxTransfers,
            'transitModes' => Modes::toQuery($query->modes),
            'numItineraries' => $query->limit,
            'pedestrianProfile' => $query->wheelchair ? 'WHEELCHAIR' : null,
            'language' => $query->language ?? $this->language,
        ]);
        if (null === $data) {
            return [];
        }

        $journeys = [];
        foreach ($data->list('itineraries') as $itinerary) {
            if (null !== $journey = $this->journey($itinerary, $query)) {
                $journeys[] = $journey;
            }
        }
        $journeys = \array_slice($journeys, 0, $query->limit);
        foreach ($data->list('direct') as $itinerary) {
            if (null !== $journey = $this->journey($itinerary, $query)) {
                $journeys[] = $journey;
            }
        }

        return $journeys;
    }

    public function stop(string $id): ?Stop
    {
        $data = $this->stopInfo($id);

        return null === $data ? null : $this->stopOf($data->data('place'))->withLines($this->routes($data));
    }

    public function nearby(Coordinates $point, int $radius = 500, int $limit = 20): array
    {
        $box = BoundingBox::around($point, max(1, $radius));
        $data = $this->json->get($this->baseUri.'/api/v6/map/stops', [
            'min' => (string) $box->southWest(),
            'max' => (string) $box->northEast(),
            'language' => $this->language,
        ]);

        return Stop::nearest(array_map($this->stopOf(...), $data?->items() ?? []), $point, $radius, $limit);
    }

    public function searchStops(string $text, int $limit = 20, ?Coordinates $near = null): array
    {
        if ('' === trim($text)) {
            return [];
        }
        $data = $this->json->get($this->baseUri.'/api/v1/geocode', [
            'text' => $text,
            'type' => 'STOP',
            'place' => null === $near ? null : (string) $near,
            'numResults' => max(1, min(50, $limit)),
            'language' => $this->language,
        ]);

        $stops = [];
        foreach ($data?->items() ?? [] as $match) {
            $id = $match->string('id');
            $lat = $match->float('lat');
            $lon = $match->float('lon');
            if (null === $id || isset($stops[$id]) || 'STOP' !== $match->string('type')) {
                continue;
            }
            $stop = new Stop($id, $match->string('name') ?? $id, $this->getName(),
                null === $lat || null === $lon ? null : new Coordinates($lat, $lon),
                timezone: $match->string('tz'),
                modes: array_values(array_unique(array_map(Modes::from(...), $match->strings('modes')), \SORT_REGULAR)),
            );
            $stops[$id] = null === $near ? $stop : $stop->from($near);
        }

        return \array_slice(array_values($stops), 0, $limit);
    }

    public function lines(): array
    {
        throw NotSupportedException::call($this->getTitle(), 'list a network\'s lines');
    }

    public function line(string $id): ?Line
    {
        throw NotSupportedException::call($this->getTitle(), 'give a line by its identifier');
    }

    public function lineStops(string $lineId, ?string $direction = null): array
    {
        throw NotSupportedException::call($this->getTitle(), 'list a line\'s stops');
    }

    public function stopLines(string $stopId): array
    {
        $data = $this->stopInfo($stopId);

        return null === $data ? [] : $this->routes($data);
    }

    public function operators(): array
    {
        throw NotSupportedException::call($this->getTitle(), 'list operators');
    }

    private function stopInfo(string $id): ?Data
    {
        return '' === trim($id) ? null : $this->json->get($this->baseUri.'/api/v6/stop', ['stopId' => $id, 'language' => $this->language]);
    }

    /** @return list<Line> */
    private function routes(Data $data): array
    {
        $lines = [];
        foreach ($data->list('routes') as $route) {
            if (null !== $line = $this->lineOf($route)) {
                $lines[$line->id] = $line;
            }
        }

        return array_values($lines);
    }

    /** A Line from a route, a stop time or a leg (they share the route's fields); null for a walk. */
    private function lineOf(Data $data): ?Line
    {
        $mode = Modes::from($data->string('mode'));
        $name = $data->string('routeShortName') ?? $data->string('displayName') ?? $data->string('routeLongName') ?? $data->string('tripShortName');
        if (!$mode->isTransit() || null === $name) {
            return null;
        }
        $agency = $data->string('agencyName');

        return new Line(
            $data->string('routeId') ?? $name,
            $name,
            $mode,
            $this->getName(),
            $data->string('routeLongName'),
            $data->string('routeColor'),
            $data->string('routeTextColor'),
            null === $agency ? null : new Operator($data->string('agencyId') ?? $agency, $agency, $this->getName(), $data->string('agencyUrl')),
            $data->string('routeUrl'),
        );
    }

    private function stopOf(Data $place): Stop
    {
        $lat = $place->float('lat');
        $lon = $place->float('lon');
        $id = $place->string('stopId') ?? (null === $lat || null === $lon ? '' : $lat.','.$lon);

        return new Stop(
            $id,
            $place->string('name') ?? $id,
            $this->getName(),
            null === $lat || null === $lon ? null : new Coordinates($lat, $lon),
            StopKind::Stop,
            $place->string('stopCode'),
            $place->string('parentId'),
            $place->string('track') ?? $place->string('scheduledTrack'),
            $place->string('tz'),
            modes: array_values(array_unique(array_map(Modes::from(...), $place->strings('modes')), \SORT_REGULAR)),
            description: $place->string('description'),
        );
    }

    private function journey(Data $itinerary, JourneyQuery $query): ?Journey
    {
        $legs = [];
        foreach ($itinerary->list('legs') as $leg) {
            $departure = $leg->dateTime('startTime');
            $arrival = $leg->dateTime('endTime');
            if (null === $departure || null === $arrival) {
                return null;
            }
            $realtime = true === $leg->bool('realTime');
            $scheduledDeparture = $leg->dateTime('scheduledStartTime');
            $scheduledArrival = $leg->dateTime('scheduledEndTime');
            $legs[] = new Leg(
                Modes::from($leg->string('mode')),
                $this->legPlace($leg->data('from'), $query->from),
                $this->legPlace($leg->data('to'), $query->to),
                $departure,
                $arrival,
                $this->lineOf($leg),
                $leg->string('headsign'),
                $leg->string('tripId'),
                array_map(fn (Data $stop) => $this->legPlace($stop, null), $leg->list('intermediateStops')),
                $realtime && $scheduledDeparture != $departure ? $scheduledDeparture : null,
                $realtime && $scheduledArrival != $arrival ? $scheduledArrival : null,
                $realtime,
                true === $leg->bool('cancelled'),
                $leg->float('distance'),
                $leg->string('legGeometry.points'),
                $leg->int('legGeometry.precision') ?? 6,
                $leg->string('tripShortName'),
            );
        }

        return [] === $legs ? null : new Journey($legs, $this->getName(), $itinerary->int('transfers'), $itinerary->string('id'));
    }

    /** A leg's end: its stop where MOTIS names one; MOTIS's "START" and "END" named as the query's places. */
    private function legPlace(Data $place, ?Place $asked): Place
    {
        $lat = $place->float('lat');
        $lon = $place->float('lon');
        $point = null === $lat || null === $lon ? null : new Coordinates($lat, $lon);
        $name = $place->string('name') ?? '';
        if (null === $place->string('stopId')) {
            if (null !== $asked && \in_array($name, ['START', 'END'], true)) {
                $name = $asked->name;
            }

            return new Place($name, $point ?? $asked?->coordinates() ?? new Coordinates(0, 0), departure: $place->dateTime('departure'), arrival: $place->dateTime('arrival'));
        }
        $stop = $this->stopOf($place);

        return new Place($stop->name, $point, $stop, $stop->platform, $place->dateTime('arrival'), $place->dateTime('departure'));
    }

    /** "lat,lon", or the stop's identifier when the place is one of Transitous' stops. */
    private function placeParameter(Place $place): string
    {
        if (null !== $place->stop && $this->getName() === $place->stop->source) {
            return $place->stop->id;
        }
        $point = $place->coordinates() ?? throw NotSupportedException::call($this->getTitle(), \sprintf('plan from a stop of another provider without its coordinates ("%s")', $place->name));

        return (string) $point;
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
