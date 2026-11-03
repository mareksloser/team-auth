<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts TOTP secrets at rest (libsodium secretbox), so a database leak alone doesn't
 * let anyone generate codes. The key comes from TOTP_ENCRYPTION_KEY; it is only needed
 * once somebody enables two-factor authentication.
 */
final class TotpSecretCipher
{
    private const PREFIX = 'v1:';

    public function __construct(
        #[Autowire('%env(default::TOTP_ENCRYPTION_KEY)%')]
        private readonly ?string $base64Key,
    ) {
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->key()));
    }

    public function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Unknown TOTP secret format.');
        }
        $raw = base64_decode(substr($stored, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Corrupted TOTP secret.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if (false === $plain) {
            throw new \RuntimeException('TOTP secret cannot be decrypted – was TOTP_ENCRYPTION_KEY changed?');
        }

        return $plain;
    }

    private function key(): string
    {
        $key = base64_decode((string) $this->base64Key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \RuntimeException('TOTP_ENCRYPTION_KEY must be 32 random bytes, base64-encoded (php -r "echo base64_encode(random_bytes(32));").');
        }

        return $key;
    }
}
