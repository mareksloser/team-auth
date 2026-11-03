<?php

declare(strict_types=1);

namespace App\OAuth;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;

/** Podepisování RS256 tokenů (access token i id_token) se stejným klíčem a `kid`. */
final class Jwt
{
    /** @param array<string, mixed> $claims vlastní (neregistrované) claimy */
    public static function sign(
        CryptKeyInterface $key,
        ?string $keyId,
        string $issuer,
        string $audience,
        string $subject,
        \DateTimeImmutable $expiresAt,
        array $claims = [],
        ?string $tokenId = null,
    ): string {
        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($key->getKeyContents(), $key->getPassPhrase() ?? ''),
            InMemory::plainText('unused-verification-key'),
        );

        $now = new \DateTimeImmutable();
        $builder = $config->builder()
            ->issuedBy($issuer)
            ->permittedFor($audience)
            ->relatedTo($subject)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt);

        if (null !== $tokenId) {
            $builder = $builder->identifiedBy($tokenId);
        }
        if (null !== $keyId) {
            $builder = $builder->withHeader('kid', $keyId);
        }
        foreach ($claims as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }
}
