<?php

declare(strict_types=1);

namespace App\Device;

use App\Entity\AppGrant;
use App\Entity\User;
use App\Entity\UserDevice;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Tracks where a user is signed in: browsers (UserDevice) and application sign-ins on them
 * (AppGrant, following the refresh token through rotation), and signs them out.
 *
 * Signing out a device ends its auth-server session on the next request and stops its
 * applications from refreshing tokens; already issued access tokens expire within their TTL.
 */
final class DeviceTracker
{
    public const COOKIE = 'device_id';
    private const SESSION_KEY = '_device';
    public const ACTIVE_DAYS = 30;          // = refresh token lifetime
    private const KEEP_DAYS = 90;
    private const TOUCH_INTERVAL = 300;     // write last_seen at most every 5 minutes

    /** Grant whose tokens are being issued in this request (set while league validates the code/refresh token). */
    private ?AppGrant $current = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly UserRepository $users,
        private readonly CredentialsRevokerInterface $credentials,
    ) {
    }

    /* ---- Browser sessions ---- */

    /** After a full sign-in: find or create the device for this browser. Returns the (refreshed) device cookie. */
    public function startDevice(User $user, Request $request): Cookie
    {
        $value = (string) $request->cookies->get(self::COOKIE, '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $value)) {
            $value = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        $hash = hash('sha256', $value);
        $now = $this->now();

        $device = $this->em->getRepository(UserDevice::class)->findOneBy(['user' => $user, 'deviceHash' => $hash, 'revokedAt' => null]);
        if (null === $device) {
            $device = new UserDevice($user, $hash, (string) $request->headers->get('User-Agent', ''), $request->getClientIp(), $now);
            $this->em->persist($device);
            $this->prune($now);
        } else {
            $device->seen((string) $request->headers->get('User-Agent', ''), $request->getClientIp(), $now);
        }
        $this->em->flush();
        $request->getSession()->set(self::SESSION_KEY, $device->getPublicId());

        return Cookie::create(self::COOKIE, $value, $now->modify('+1 year'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    /** The device of the current auth-server session, if tracked. */
    public function currentDevice(Request $request): ?UserDevice
    {
        if (!$request->hasSession()) {
            return null;
        }
        $publicId = $request->getSession()->get(self::SESSION_KEY);

        return \is_string($publicId) ? $this->em->getRepository(UserDevice::class)->findOneBy(['publicId' => $publicId]) : null;
    }

    public function touch(UserDevice $device, Request $request): void
    {
        $now = $this->now();
        if ($now->getTimestamp() - $device->getLastSeenAt()->getTimestamp() >= self::TOUCH_INTERVAL) {
            $device->seen((string) $request->headers->get('User-Agent', ''), $request->getClientIp(), $now);
            $this->em->flush();
        }
    }

    /* ---- Hooks from the OAuth repositories ---- */

    /** /oauth/authorize issued a code: a new application sign-in on the current browser. */
    public function grantStarted(string $authCodeId, string $userUuid, string $clientId): void
    {
        $user = $this->users->findOneBy(['uuid' => $userUuid]);
        if (null === $user) {
            return;
        }
        $request = $this->requests->getMainRequest();
        $device = null !== $request ? $this->currentDevice($request) : null;
        $this->em->persist(new AppGrant($user, $device, $clientId, $authCodeId, $this->now()));
        $this->em->flush();
    }

    /** The application redeems the code; the tokens issued next belong to this grant. */
    public function resumeByAuthCode(string $authCodeId): bool
    {
        $this->current = $this->em->getRepository(AppGrant::class)->findOneBy(['authCodeId' => $authCodeId]);

        return null === $this->current || !$this->current->isRevoked();
    }

    /** The application refreshes; false = the grant (or its device) was signed out. */
    public function resumeByRefreshToken(string $refreshTokenId): bool
    {
        $this->current = $this->em->getRepository(AppGrant::class)->findOneBy(['refreshTokenId' => $refreshTokenId]);

        return null === $this->current || !$this->current->isRevoked();
    }

    public function refreshTokenIssued(string $refreshTokenId): void
    {
        if (null === $this->current) {
            return;
        }
        $this->current->useRefreshToken($refreshTokenId, $this->now());
        $this->em->flush();
    }

    /* ---- Signing out ---- */

    public function signOutDevice(UserDevice $device): void
    {
        $now = $this->now();
        $device->revoke($now);
        $refreshTokens = [];
        foreach ($this->em->getRepository(AppGrant::class)->findBy(['device' => $device, 'revokedAt' => null]) as $grant) {
            $grant->revoke($now);
            $refreshTokens[] = $grant->getRefreshTokenId();
        }
        $this->em->flush();
        $this->revokeRefreshTokens(array_filter($refreshTokens));
    }

    /** Logout on this browser: the auth-server session and the applications used in it. */
    public function signOutCurrentDevice(Request $request): void
    {
        $device = $this->currentDevice($request);
        if (null !== $device) {
            $this->signOutDevice($device);
        }
    }

    /**
     * Everything except $keep (e.g. "sign out other devices"). Without $keep also revokes
     * tokens issued before devices were tracked – used after a password change or deactivation.
     */
    public function signOutEverywhere(User $user, ?UserDevice $keep = null): void
    {
        $now = $this->now();
        $keepTokens = [];
        foreach ($this->em->getRepository(UserDevice::class)->findBy(['user' => $user, 'revokedAt' => null]) as $device) {
            if ($device !== $keep) {
                $device->revoke($now);
            }
        }
        foreach ($this->em->getRepository(AppGrant::class)->findBy(['user' => $user, 'revokedAt' => null]) as $grant) {
            if (null !== $keep && $grant->getDevice() === $keep) {
                $keepTokens[] = $grant->getRefreshTokenId();
                continue;
            }
            $grant->revoke($now);
        }
        $this->em->flush();

        if (null === $keep) {
            // Access tokens, refresh tokens and codes of the user, tracked or not (sub = uuid).
            $this->credentials->revokeCredentialsForUser(new InMemoryUser($user->getUuid(), null));

            return;
        }
        $this->revokeRefreshTokensOfUser($user, array_values(array_filter($keepTokens)));
    }

    /* ---- Listing ---- */

    /**
     * Devices with recent activity, newest first, with the applications used on them.
     *
     * @return list<array{device: ?UserDevice, grants: list<AppGrant>, lastSeen: \DateTimeImmutable}>
     */
    public function activeDevices(User $user): array
    {
        $since = $this->now()->modify(sprintf('-%d days', self::ACTIVE_DAYS));
        $rows = [];
        foreach ($this->em->getRepository(UserDevice::class)->findBy(['user' => $user, 'revokedAt' => null]) as $device) {
            $rows[$device->getPublicId()] = ['device' => $device, 'grants' => [], 'lastSeen' => $device->getLastSeenAt()];
        }
        foreach ($this->em->getRepository(AppGrant::class)->findBy(['user' => $user, 'revokedAt' => null]) as $grant) {
            if ($grant->isRevoked()) {
                continue;
            }
            $key = $grant->getDevice()?->getPublicId() ?? 'untracked';
            $rows[$key] ??= ['device' => null, 'grants' => [], 'lastSeen' => $grant->getLastSeenAt()];
            $rows[$key]['grants'][] = $grant;
            if ($grant->getLastSeenAt() > $rows[$key]['lastSeen']) {
                $rows[$key]['lastSeen'] = $grant->getLastSeenAt();
            }
        }
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['lastSeen'] >= $since));
        usort($rows, static fn (array $a, array $b): int => $b['lastSeen'] <=> $a['lastSeen']);

        return $rows;
    }

    public function findDevice(User $user, string $publicId): ?UserDevice
    {
        return $this->em->getRepository(UserDevice::class)->findOneBy(['user' => $user, 'publicId' => $publicId]);
    }

    /** @param list<string> $ids */
    private function revokeRefreshTokens(array $ids): void
    {
        if ([] === $ids) {
            return;
        }
        $this->em->createQuery('UPDATE '.RefreshToken::class.' rt SET rt.revoked = true WHERE rt.identifier IN (:ids)')
            ->setParameter('ids', array_values($ids))
            ->execute();
    }

    /** @param list<string> $keep */
    private function revokeRefreshTokensOfUser(User $user, array $keep): void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->update(RefreshToken::class, 'rt')
            ->set('rt.revoked', ':revoked')
            ->where($qb->expr()->in('rt.accessToken', $this->em->createQueryBuilder()
                ->select('at.identifier')->from(AccessToken::class, 'at')->where('at.userIdentifier = :uuid')->getDQL()))
            ->setParameter('revoked', true)
            ->setParameter('uuid', $user->getUuid());
        if ([] !== $keep) {
            $qb->andWhere('rt.identifier NOT IN (:keep)')->setParameter('keep', $keep);
        }
        $qb->getQuery()->execute();
    }

    private function prune(\DateTimeImmutable $now): void
    {
        $before = $now->modify(sprintf('-%d days', self::KEEP_DAYS));
        $this->em->createQuery('DELETE '.AppGrant::class.' g WHERE g.lastSeenAt < :before')->setParameter('before', $before)->execute();
        $this->em->createQuery('DELETE '.UserDevice::class.' d WHERE d.lastSeenAt < :before AND d.id NOT IN (SELECT IDENTITY(g.device) FROM '.AppGrant::class.' g WHERE g.device IS NOT NULL)')
            ->setParameter('before', $before)->execute();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
