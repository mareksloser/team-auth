<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Remembers the /oauth/authorize URL an application sent the user to, separately from the
 * firewall's target path. The target path is overwritten by any GET that needs a login
 * (e.g. the browser fetching /favicon.ico while the login or two-factor page is open),
 * which used to strand users on the account page instead of returning to the application.
 */
final class OAuthReturn
{
    public const AUTHORIZE_PATH = '/oauth/authorize';
    private const SESSION_KEY = '_oauth_return';
    private const MAX_AGE = 900; // a sign-in that takes longer than 15 minutes starts over

    public function remember(Request $request): void
    {
        $request->getSession()->set(self::SESSION_KEY, ['url' => $request->getUri(), 'at' => time()]);
    }

    /** The authorize URL to continue with, if a sign-in for an application is in progress. */
    public function pending(SessionInterface $session): ?string
    {
        $data = $session->get(self::SESSION_KEY);
        if (!\is_array($data) || !\is_string($data['url'] ?? null) || !\is_int($data['at'] ?? null)) {
            return null;
        }
        if (time() - $data['at'] > self::MAX_AGE) {
            $session->remove(self::SESSION_KEY);

            return null;
        }

        return $data['url'];
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /** client_id of the pending authorize URL. */
    public static function clientId(?string $authorizeUrl): ?string
    {
        if (null === $authorizeUrl || self::AUTHORIZE_PATH !== parse_url($authorizeUrl, \PHP_URL_PATH)) {
            return null;
        }
        parse_str((string) parse_url($authorizeUrl, \PHP_URL_QUERY), $query);
        $clientId = $query['client_id'] ?? null;

        return \is_string($clientId) && '' !== $clientId ? $clientId : null;
    }
}
