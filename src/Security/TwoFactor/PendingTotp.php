<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\User;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;

/** A secret being set up (not saved yet): lets the TOTP authenticator build the QR code and check the first code. */
final class PendingTotp implements TwoFactorInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $secret,
    ) {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return true;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, User::TOTP_PERIOD, User::TOTP_DIGITS);
    }
}
