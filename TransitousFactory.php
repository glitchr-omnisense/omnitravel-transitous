<?php

namespace Omnitravel\Transitous;

use Omnitravel\Config;
use Omnitravel\Exception\InvalidConfigException;
use Omnitravel\Http\JsonClient;
use Omnitravel\ProviderFactory;
use Omnitravel\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "transitous": options user_agent (required: Transitous' usage policy asks
 * every request to name the application, its version and a way to reach
 * its operator, "MySite/1.0 (https://example.org; travel@example.org)"),
 * base_uri (https://api.transitous.org; a MOTIS 2 server of one's own),
 * language (the names' language, as OpenStreetMap tags it: "fr"; none by
 * default), timeout (seconds; the HTTP client's own when empty); policy (a
 * tightening of Transitous' usage policy: a lower daily share of journeys...),
 * usage (commercial, open_source: a commercial site is refused at once).
 */
final class TransitousFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnitravel.factory_name' => 'transitous',
            'omnitravel.factory_title' => 'Transitous',
            'omnitravel.required_options' => ['user_agent'],
            'base_uri' => 'https://api.transitous.org',
            'language' => null,
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $agent = (string) $c->string('user_agent');
        if (!preg_match('~/.*[(;@]|[(;@].*/~', $agent)) {
            throw new InvalidConfigException('Transitous asks every request to name the application, its version and a contact: "MySite/1.0 (https://example.org; travel@example.org)".');
        }
        $base = rtrim((string) $c->string('base_uri'), '/');
        if (!preg_match('~^https?://[^/\s]+~', $base)) {
            throw new InvalidConfigException(\sprintf('The "transitous" provider needs a base_uri such as https://api.transitous.org, not "%s".', $base));
        }
        $http = $this->http ?? HttpClient::create(['timeout' => 20]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }

        return new TransitousProvider(new JsonClient($http, 'Transitous', ['User-Agent' => $agent, 'Accept' => 'application/json'], $c->backoff()), $base, $c->string('language'));
    }
}
