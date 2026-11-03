<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\TwoFactor\TotpSecretCipher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/** Decrypts the TOTP secret when a user is loaded, so the TOTP authenticator can check codes. */
#[AsEntityListener(event: Events::postLoad, method: 'postLoad', entity: User::class)]
final class TotpSecretLoader
{
    public function __construct(
        private readonly TotpSecretCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postLoad(User $user): void
    {
        $encrypted = $user->getTotpSecretEncrypted();
        if (null === $encrypted) {
            return;
        }
        try {
            $user->unlockTotpSecret($this->cipher->decrypt($encrypted));
        } catch (\RuntimeException $e) {
            // Leave it locked: User::getTotpAuthenticationConfiguration() then fails the login (fail closed).
            $this->logger->error('Cannot decrypt TOTP secret of user {uuid}: {message}', ['uuid' => $user->getUuid(), 'message' => $e->getMessage()]);
        }
    }
}
