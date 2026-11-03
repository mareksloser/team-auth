<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Public key as JWK for /.well-known/jwks.json; `kid` = RFC 7638 thumbprint. */
final class KeySet
{
    /** @var array<string, string>|null */
    private ?array $jwk = null;

    public function __construct(
        #[Autowire('%env(resolve:OAUTH_PUBLIC_KEY)%')]
        private readonly string $publicKeyPath,
    ) {
    }

    /** @return array<string, string> */
    public function jwk(): array
    {
        if (null !== $this->jwk) {
            return $this->jwk;
        }

        $path = preg_replace('#^file://#', '', $this->publicKeyPath);
        $key = openssl_pkey_get_public((string) file_get_contents($path));
        if (false === $key) {
            throw new \RuntimeException('Nelze načíst veřejný klíč: '.$path);
        }
        $details = openssl_pkey_get_details($key);
        if (!isset($details['rsa'])) {
            throw new \RuntimeException('Veřejný klíč musí být RSA.');
        }

        $n = self::b64url($details['rsa']['n']);
        $e = self::b64url($details['rsa']['e']);
        $kid = self::b64url(hash('sha256', json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], JSON_THROW_ON_ERROR), true));

        return $this->jwk = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $n, 'e' => $e];
    }

    public function keyId(): string
    {
        return $this->jwk()['kid'];
    }

    private static function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
