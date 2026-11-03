<?php

declare(strict_types=1);

namespace App\OAuth;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;

/** Signs RS256 tokens (access token and id_token) with the same key and `kid`. */
final class Jwt
{
    /** @param array<string, mixed> $claims custom (non-registered) claims */
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
        // Integer iat/nbf/exp: the default formatter emits microseconds (e.g. 1793736265.301978),
        // which RFC 7519 allows but many resource servers reject.
        $builder = $config->builder(ChainedFormatter::withUnixTimestampDates())
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
