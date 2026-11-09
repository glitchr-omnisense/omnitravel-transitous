---
title: Transitous
order: 30
---

# omnitravel/transitous

Transitous is a community-run [MOTIS 2](https://github.com/motis-project/motis) server
(https://api.transitous.org) over the open GTFS feeds, real-time feeds and OpenStreetMap data it
gathers worldwide. No account, no key: a `User-Agent` that names the application and a contact.
Endpoints read from MOTIS's OpenAPI (`openapi.yaml` of motis-project/motis, API v6, read on
2026-11-09).

```sh
composer require omnisense/omnitravel omnitravel/transitous
```

## Options

| Option | Default | |
|---|---|---|
| `user_agent` | (required) | `MySite/1.0 (https://example.org; travel@example.org)`: a name, a version and a contact (a URL or an address), as the usage policy asks; one without a version or a contact is refused (`InvalidConfigException`) |
| `base_uri` | `https://api.transitous.org` | another MOTIS 2 server (one of your own: the same API); never `staging.api.transitous.org`, which the policy keeps from applications |
| `language` | none | the names' language (`fr`, `en`, `ja`): MOTIS's `language`, as OpenStreetMap and GTFS translations tag it; a `DepartureQuery`'s or `JourneyQuery`'s own wins |
| `timeout` | the client's | seconds; the factory's own client waits 20 |

The factory takes the application's HTTP client (`?HttpClientInterface`), and makes
`HttpClient::create(['timeout' => 20])` without one.

## Capabilities

`timetables`, `arrivals`, `realtime`, `itineraries`, `network` with `nearby` and `stopSearch`,
`platforms`; `lines: false` - MOTIS keeps no list of a network's lines: `lines()`, `line()`,
`lineStops()` and `operators()` throw `NotSupportedException`. `keyless`, no `countries` (anywhere a
feed covers), `licence` ODbL-1.0 (OpenStreetMap's; each feed has its own, listed on the sources
page), `attribution` "Transitous (https://transitous.org/sources/) and OpenStreetMap contributors",
`terms` the API policy, the sources page and OpenStreetMap's copyright page. `policy`: the usage
policy of [policy.json](../policy.json) (`TransitousProvider::usagePolicy()`): 720 journeys a day
(the operation `journeys`, Transitous' `/plan`), commercial use forbidden, open source required,
the sources page credited on the display and the map, a User-Agent with a version and a contact;
caching and storage unknown (the policy does not address them).

## The calls

| Method | Call | Parameters sent |
|---|---|---|
| `departures()` | `GET /api/v6/stoptimes` | `stopId`, `time` (UTC, when the query has one), `n` (limit), `window`, `arriveBy=true` for arrivals, `mode` (MOTIS's names, comma-separated), `language` |
| `journeys()` | `GET /api/v6/plan` | `fromPlace`, `toPlace` (a Transitous stop's id, otherwise `lat,lon`), `time`, `arriveBy=true`, `maxTransfers`, `transitModes`, `numItineraries` (the limit), `pedestrianProfile=WHEELCHAIR`, `language` |
| `stop()`, `stopLines()` | `GET /api/v6/stop` | `stopId`, `language` |
| `nearby()` | `GET /api/v6/map/stops` | `min` and `max` (south-west and north-east of the square around the circle), `language` |
| `searchStops()` | `GET /api/v1/geocode` | `text`, `type=STOP`, `place` (the point to bias towards), `numResults` (1-50), `language` |

A parameter left empty is not sent. Every request carries `User-Agent` and
`Accept: application/json`. A 404 is "not known": `stop()` gives null, `departures()` and
`journeys()` an empty list. Any other error status is a `ProviderException` naming the path and
MOTIS's message (`Transitous answered 422 for GET /api/v6/stoptimes. {"error": ...}`); a network
failure an `UnreachableException` (the core's `JsonClient`).

## How fields are read

- **Identifiers** are MOTIS's: the feed's own id prefixed with its dataset tag
  (`fr-angers-loire-metropole-reseau-irigo-gtfs-gtfs-rt-siri_1RALL`). They are what the other calls
  take; an application keeps them as given. MOTIS documents them as unstable across imports
  (`stoptimes` takes a `center` to hedge against it; not used here).
- **Departure**: `place.scheduledDeparture` (`scheduledArrival` for arrivals) is the scheduled time;
  `place.departure` is the expected one only when `realTime` is true (without it `expected` is null:
  no guess). `cancelled` is the stop time's `cancelled`, its trip's `tripCancelled` or the place's.
  `platform` is `place.track` (else `scheduledTrack`), `scheduledPlatform` the scheduled one when it
  changed. `headsign`, `tripId`, `tripShortName` (the number), `mode`; an arrival's `origin` is
  `tripFrom.name`. The departure's stop is the quay it leaves from (`place`), which may be another
  of the station's stops than the one asked (`2RALL` for `1RALL`).
- **Line**: `routeId`, `routeShortName` (else `displayName`, `routeLongName`, `tripShortName`),
  `routeLongName`, `routeColor` and `routeTextColor` (normalised to `#RRGGBB` by the core), the
  operator from `agencyId`, `agencyName`, `agencyUrl`; `routeUrl`. A walk has no line.
- **Journey**: each itinerary's legs, `transfers` and `id`; its duration is counted from its times
  (MOTIS rounds them to the minute: its own `duration`, in seconds, can be a little longer). A leg's
  `startTime`/`endTime`, its scheduled times when `realTime` moved them, `distance` (metres, walks),
  `legGeometry` (`points` and its `precision`, 6 in API v6: `Leg::path()` decodes it),
  `intermediateStops` as places with their times, `cancelled`. MOTIS names the ends of a journey
  asked by coordinates "START" and "END": they take the query's place names. `direct` journeys
  (on foot by default) come after the itineraries, outside the limit.
- **Modes**: MOTIS's `TRAM`, `SUBWAY` (metro), `SUBURBAN`, `RAIL`, `HIGHSPEED_RAIL`,
  `LONG_DISTANCE`, `NIGHT_RAIL`, `REGIONAL_RAIL` and `REGIONAL_FAST_RAIL` (regional rail), `BUS`,
  `COACH`, `FERRY`, `AIRPLANE`, `FUNICULAR`, `AERIAL_LIFT`, `CABLE_CAR`, `ODM`, `RIDE_SHARING` and
  `FLEX` (on demand), `WALK`, `BIKE` and `RENTAL`, `CAR` and its variants; `METRO` and `AREAL_LIFT`,
  the names before API v5, are read as `SUBURBAN` and `AERIAL_LIFT`. A trolleybus is asked as `BUS`,
  a monorail as `OTHER`.
- **Stops near a point**: MOTIS answers every stop of the square; the core's `Stop::nearest()` keeps
  those within the radius, nearest first, with their distance.
- **Stops by name**: geocoding matches of type `STOP`, each once (MOTIS can return a stop several
  times), in MOTIS's order, with their distance from `$near` when given.

## An example

```php
$transitous = (new TransitousFactory(HttpClient::create(['timeout' => 30])))->create([
    'user_agent' => 'omnitravel-tests/1.0 (+https://github.com/glitchr-omnisense/omnitravel; marco.meyer@glitchr.io)',
    'language' => 'fr',
]);
$ralliement = $transitous->searchStops('Ralliement', 5, new Coordinates(47.4712, -0.5518))[0];
foreach ($transitous->departures(new DepartureQuery($ralliement->id, limit: 6)) as $d) {
    printf("%s %s  %s  %s%s\n", $d->line?->name, $d->headsign, $d->scheduled->format('H:i'),
        null === $d->delay() ? 'no real time' : sprintf('%+d min', $d->delay() / 60), $d->cancelled ? '  cancelled' : '');
}
foreach ($transitous->journeys(new JourneyQuery(Place::at(47.4712, -0.5518), Place::at(47.4608, -0.5306), limit: 3)) as $j) {
    echo $j->departure()->format('H:i'), '-', $j->arrival()->format('H:i'), ' ',
        implode(' > ', array_map(static fn ($l) => $l->line?->name ?? $l->mode->value, $j->legs)), "\n";
}
```

```
C Belle-Beille Campus  12:33  +2 min
C Roseraie  12:39  +0 min
C Belle-Beille Campus  12:44  +0 min
C Roseraie  12:50  no real time
C Belle-Beille Campus  12:56  +0 min
C Roseraie  13:01  +0 min  cancelled
12:38-12:53 walk > 8 > walk
12:44-13:09 walk > 1 > walk
12:51-13:12 walk > 7 > walk
12:34-13:03 walk
```

(times in UTC. The script ran on 2026-11-09 over the answers Transitous gave that day at 12:34 UTC,
in Angers - Irigo's tram C at Ralliement, and from the city centre to the Raymond Kopa stadium:
three calls, one after the other, recorded as the package's fixtures and trimmed to the fields
read. The last line is MOTIS's direct walk, which leaves at the time asked.)

## Verified, and not

- Verified: the three answers above, recorded live on 2026-11-09 (geocode, stoptimes, plan), and
  `LiveTest` (the same three calls, `docker compose run --rm omnitravel live --filter Transitous`),
  green the same day. The tests check every request's path and parameters and the User-Agent.
- Not called live: `/api/v6/stop` and `/api/v6/map/stops`. Their fixtures are written from the
  OpenAPI's schemas (`place` + `routes`; a list of places) with the stops and the route the recorded
  answers carried.
- Not used: paging cursors (`pageCursor`), `/api/v6/trip`, `/api/v6/map/trips` (vehicles drawn
  from timetables, not tracked: no `TrackingInterface` here), fares, rentals, the experimental
  endpoints.
