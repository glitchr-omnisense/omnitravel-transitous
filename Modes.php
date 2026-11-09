<?php

namespace Omnitravel\Transitous;

use Omnitravel\Model\Mode;

/**
 * MOTIS's modes (the Mode enum of its OpenAPI, v6) and omnitravel's, both
 * ways. MOTIS's METRO and AREAL_LIFT are the names its API used before v5
 * (SUBURBAN, AERIAL_LIFT now): still read, never sent.
 */
final class Modes
{
    private const FROM = [
        'WALK' => Mode::Walk, 'BIKE' => Mode::Bike, 'RENTAL' => Mode::Bike,
        'CAR' => Mode::Car, 'HGV' => Mode::Car, 'CAR_PARKING' => Mode::Car, 'CAR_DROPOFF' => Mode::Car,
        'ODM' => Mode::OnDemand, 'RIDE_SHARING' => Mode::OnDemand, 'FLEX' => Mode::OnDemand,
        'TRAM' => Mode::Tram, 'SUBWAY' => Mode::Metro, 'FERRY' => Mode::Ferry, 'AIRPLANE' => Mode::Air,
        'BUS' => Mode::Bus, 'COACH' => Mode::Coach, 'RAIL' => Mode::Rail, 'HIGHSPEED_RAIL' => Mode::HighSpeedRail,
        'LONG_DISTANCE' => Mode::LongDistanceRail, 'NIGHT_RAIL' => Mode::NightRail,
        'REGIONAL_FAST_RAIL' => Mode::RegionalRail, 'REGIONAL_RAIL' => Mode::RegionalRail,
        'SUBURBAN' => Mode::Suburban, 'METRO' => Mode::Suburban,
        'FUNICULAR' => Mode::Funicular, 'AERIAL_LIFT' => Mode::AerialLift, 'AREAL_LIFT' => Mode::AerialLift,
        'CABLE_CAR' => Mode::CableCar,
    ];

    /** @var array<string, list<string>> */
    private const TO = [
        'tram' => ['TRAM'], 'metro' => ['SUBWAY'], 'rail' => ['RAIL'], 'suburban' => ['SUBURBAN'],
        'regional-rail' => ['REGIONAL_RAIL', 'REGIONAL_FAST_RAIL'], 'high-speed-rail' => ['HIGHSPEED_RAIL'],
        'long-distance-rail' => ['LONG_DISTANCE'], 'night-rail' => ['NIGHT_RAIL'],
        'bus' => ['BUS'], 'trolleybus' => ['BUS'], 'coach' => ['COACH'], 'ferry' => ['FERRY'],
        'cable-car' => ['CABLE_CAR'], 'aerial-lift' => ['AERIAL_LIFT'], 'funicular' => ['FUNICULAR'],
        'air' => ['AIRPLANE'], 'on-demand' => ['ODM'], 'monorail' => ['OTHER'], 'other' => ['OTHER'],
    ];

    public static function from(?string $motis): Mode
    {
        return self::FROM[strtoupper((string) $motis)] ?? Mode::Other;
    }

    /**
     * MOTIS's transit modes for these, comma-separated as its API takes an
     * array (explode: false); null when none is asked (every mode).
     *
     * @param list<Mode> $modes
     */
    public static function toQuery(array $modes): ?string
    {
        $motis = [];
        foreach ($modes as $mode) {
            foreach (self::TO[$mode->value] ?? [] as $one) {
                $motis[$one] = true;
            }
        }

        return [] === $motis ? null : implode(',', array_keys($motis));
    }
}
