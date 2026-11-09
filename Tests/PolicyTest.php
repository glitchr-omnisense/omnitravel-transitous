<?php

namespace Omnitravel\Transitous\Tests;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnitravel\Exception\OmnitravelException;
use Omnitravel\Exception\UsageLimitReachedException;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\JourneyQuery;
use Omnitravel\Model\Place;
use Omnitravel\Registry;
use Omnitravel\Testing\RecordedHttpClient;
use Omnitravel\Transitous\TransitousFactory;
use Omnitravel\Transitous\TransitousProvider;
use PHPUnit\Framework\TestCase;

/** Transitous' usage policy, declared, enforced on every call through the registry, tightened by a site, never loosened. */
final class PolicyTest extends TestCase
{
    private const UA = 'omnitravel-tests/1.0 (+https://github.com/glitchr-omnisense/omnitravel; tests@example.org)';

    public function testThePolicyIsTheOneTransitousPublishes(): void
    {
        $policy = (new Registry([new TransitousFactory(self::http())], ['planner' => ['factory' => 'transitous', 'options' => ['user_agent' => self::UA]]]))->policy('planner');

        self::assertSame('transitous', $policy->provider);
        self::assertSame(['720/day/application/journeys'], array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertSame([], $policy->limitsFor('departures'), 'only /plan is counted');
        self::assertSame(Permission::Forbidden, $policy->rights->commercial);
        self::assertTrue($policy->rights->openSourceRequired);
        self::assertSame('https://transitous.org/sources/', $policy->rights->attribution->url);
        self::assertTrue($policy->requirements->userAgent);
        self::assertTrue($policy->requirements->acceptsUserAgent(self::UA));
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame(TransitousProvider::ATTRIBUTION, $policy->rights->attribution->text, 'the policy and the provider credit the same');
    }

    public function testASitesDailyShareIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = self::http();
        $registry = new Registry([new TransitousFactory($http)], ['planner' => [
            'factory' => 'transitous',
            'options' => ['user_agent' => self::UA],
            'policy' => ['rateLimits' => [['max' => 2, 'window' => 'day', 'operations' => ['journeys']]]],
        ]]);
        $query = new JourneyQuery(Place::at(47.4720, -0.5540), Place::at(47.4612, -0.5570));

        $registry->itineraries('planner')->journeys($query);
        $registry->itineraries('planner')->journeys($query);
        self::assertSame(0, $registry->guard('planner')->remaining('journeys'));
        try {
            $registry->itineraries('planner')->journeys($query);
            self::fail('The site\'s third journey of the day was planned.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnitravelException::class, $e, 'the family\'s catch holds it');
            self::assertSame('2/day/application/journeys', $e->limit?->id());
            self::assertGreaterThan(86000, $e->retryAfter);
        }
        self::assertCount(2, $http->requests, 'the refused call reached nothing');
        $registry->timetables('planner')->departures(new DepartureQuery('stop'));
        self::assertCount(3, $http->requests, 'departures are not counted against journeys');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~/api/v6/plan~' => [429, '{"error":"too many requests"}', ['retry-after' => ['300']]]]);
        $registry = new Registry([new TransitousFactory($http)], ['planner' => ['factory' => 'transitous', 'options' => ['user_agent' => self::UA]]]);

        try {
            $registry->itineraries('planner')->journeys(new JourneyQuery(Place::at(47.4720, -0.5540), Place::at(47.4612, -0.5570)));
            self::fail('A 429 was read as journeys.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(300, $e->retryAfter);
            self::assertSame('transitous answered 429 Too Many Requests: try again in 300 s.', $e->getMessage());
        }
        try {
            $registry->timetables('planner')->departures(new DepartureQuery('stop'));
            self::fail('A call went out while Transitous asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(299, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the wait held the next request back');
        self::assertGreaterThanOrEqual(299, $registry->guard('planner')->backoff()->left());
    }

    public function testASiteCannotRaiseTransitousShare(): void
    {
        $registry = new Registry([new TransitousFactory(self::http())], ['planner' => ['factory' => 'transitous', 'options' => ['user_agent' => self::UA, 'policy' => ['rateLimits' => [['max' => 5000, 'window' => 'day', 'operations' => ['journeys']]]]]]]);

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('transitous\'s usage policy: its rate limit is 720 a day for journeys, the configuration asks 5000 a day for journeys.');
        $registry->itineraries('planner');
    }

    public function testACommercialSiteIsRefusedAtOnce(): void
    {
        $registry = new Registry([new TransitousFactory(self::http())], ['planner' => ['factory' => 'transitous', 'options' => ['user_agent' => self::UA], 'usage' => ['commercial' => true]]]);

        $this->expectException(UsageNotPermitted::class);
        $this->expectExceptionMessage('commercial use is forbidden');
        $registry->itineraries('planner');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            '~/api/v6/plan~' => [200, '@'.__DIR__.'/Fixtures/plan-angers.json'],
            '~/api/v6/stoptimes~' => [200, '@'.__DIR__.'/Fixtures/stoptimes-ralliement.json'],
        ]);
    }
}
