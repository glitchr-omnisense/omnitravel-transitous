# omnitravel/transitous

[omnisense/omnitravel](https://github.com/glitchr-omnisense/omnitravel)'s Transitous, under any PHP
host, with no key: [Transitous](https://transitous.org) is a community-run
[MOTIS 2](https://github.com/motis-project/motis) instance over the open timetables it gathers
worldwide, with their real-time feeds and OpenStreetMap for the walks.

- **journeys** from A to B (`/api/v6/plan`): legs on foot and on board, the line, its colours, the
  headsign, the stops called at, the path as a polyline, the real-time departure;
- **a stop's departures and arrivals** (`/api/v6/stoptimes`): the scheduled and the expected time,
  the track, cancellations;
- **a stop and its lines** (`/api/v6/stop`), **the stops near a point** (`/api/v6/map/stops`),
  **stops by name** (`/api/v1/geocode`).

```php
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Transitous\TransitousFactory;

$transitous = (new TransitousFactory($httpClient))->create([
    'user_agent' => 'MySite/1.0 (https://example.org; travel@example.org)',
    'language' => 'fr',
]);
$stop = $transitous->searchStops('Ralliement', 1, new Coordinates(47.4712, -0.5518))[0];
foreach ($transitous->departures(new DepartureQuery($stop->id, limit: 5)) as $departure) {
    echo $departure->line?->name, ' ', $departure->headsign, ' ', $departure->time()->format('H:i'), "\n";
}
```

Options: `user_agent` (required), `base_uri` (`https://api.transitous.org`; another MOTIS 2 server),
`language`, `timeout`.

## Transitous' usage policy, as read on 2026-11-10

[transitous.org/api](https://transitous.org/api/): the API is for open-source, non-commercial
projects that are light on its resources, on a best-effort basis.

- Every request carries a `User-Agent` naming the application, its version and a contact: the
  factory refuses one without (`MySite/1.0 (https://example.org; travel@example.org)`).
- More than 720 requests a day of `/plan` (or `/one-to-many`, `/one-to-all`) is heavy use, to be
  agreed with Transitous first: keep what you read (a cache of the application's), plan on a
  traveller's request only. Don't scrape: the data itself can be downloaded.
- Link to [transitous.org/sources](https://transitous.org/sources/) in a visible place, follow each
  source's conditions, and OpenStreetMap's attribution guidelines
  (`Capabilities::$attribution`, `$terms`).
- Commercial use is not allowed; the staging server is not for applications.

The package declares it as data, in [policy.json](policy.json) (omnisense/contracts' usage
policy, `verifiedAt` 2026-11-10): through omnitravel's registry, the 721st journey of a day is
refused before it reaches Transitous (`UsageLimitReachedException`), a 429 and its `Retry-After`
hold every call until the wait is over, and a site may lower the share
(`'policy' => ['rateLimits' => [['max' => 600, 'window' => 'day', 'operations' => ['journeys']]]]`),
never raise it. A site that says it is commercial (`'usage' => ['commercial' => true]`) is refused.

The manual: [docs/index.md](docs/index.md). MIT.
