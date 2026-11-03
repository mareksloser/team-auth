<?php

declare(strict_types=1);

namespace App\EventListener;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * OIDC RP-initiated logout: /logout?client_id=…&post_logout_redirect_uri=…&state=…
 * Redirects only to an origin that is among the client's redirect URIs.
 */
#[AsEventListener(event: LogoutEvent::class)]
final class OidcLogoutListener
{
    public function __construct(
        private readonly ClientManagerInterface $clients,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $target = (string) $request->query->get('post_logout_redirect_uri', '');
        $clientId = (string) ($request->query->get('client_id') ?? $this->audienceFromIdTokenHint($request) ?? '');

        if ('' !== $target && '' !== $clientId && $this->isAllowed($clientId, $target)) {
            $state = $request->query->get('state');
            if (null !== $state) {
                $target .= (str_contains($target, '?') ? '&' : '?').'state='.rawurlencode((string) $state);
            }
            $event->setResponse(new RedirectResponse($target));

            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_login', ['logged_out' => 1])));
    }

    private function isAllowed(string $clientId, string $target): bool
    {
        $client = $this->clients->find($clientId);
        if (null === $client || !$client->isActive()) {
            return false;
        }

        $wanted = self::origin($target);
        foreach ($client->getRedirectUris() as $uri) {
            if (null !== $wanted && self::origin((string) $uri) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private static function origin(string $url): ?string
    {
        $p = parse_url($url);
        if (!isset($p['scheme'], $p['host'])) {
            return null;
        }

        return strtolower($p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : ''));
    }

    /** Only used to identify the client – security comes from checking the origin against redirect URIs. */
    private function audienceFromIdTokenHint(Request $request): ?string
    {
        $parts = explode('.', (string) $request->query->get('id_token_hint', ''));
        if (3 !== \count($parts)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $aud = $payload['aud'] ?? null;

        return \is_array($aud) ? ($aud[0] ?? null) : (\is_string($aud) ? $aud : null);
    }
}
