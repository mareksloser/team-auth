<?php

declare(strict_types=1);

namespace App;

use App\OAuth\AccessTokenRepository;
use App\OAuth\IdTokenResponse;
use App\OAuth\UserConverter;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverter as BundleUserConverter;
use League\Bundle\OAuth2ServerBundle\Repository\AccessTokenRepository as BundleAccessTokenRepository;
use League\OAuth2\Server\AuthorizationServer;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Wires the OIDC layer into league/oauth2-server-bundle.
 * Services are located by class rather than by ID so this survives ID changes between bundle versions.
 */
class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public function process(ContainerBuilder $container): void
    {
        $bundleRepositoryId = null;
        $authorizationServer = null;

        foreach ($container->getDefinitions() as $id => $definition) {
            match ($definition->getClass()) {
                BundleAccessTokenRepository::class => $bundleRepositoryId = $id,
                AuthorizationServer::class => $authorizationServer = $definition,
                // token `sub` = user UUID, not e-mail
                BundleUserConverter::class => $definition->setClass(UserConverter::class),
                default => null,
            };
        }

        if (null === $bundleRepositoryId || null === $authorizationServer) {
            throw new \LogicException('Nenalezen AuthorizationServer nebo AccessTokenRepository z league/oauth2-server-bundle.');
        }

        // Our repository issues JWTs with roles/teams claims and delegates persistence to the original one.
        $container->getDefinition(AccessTokenRepository::class)
            ->setArgument('$inner', new Reference($bundleRepositoryId));

        $authorizationServer->replaceArgument(1, new Reference(AccessTokenRepository::class));
        // 6th constructor argument = ResponseTypeInterface → adds id_token
        $authorizationServer->setArgument(5, new Reference(IdTokenResponse::class));
    }
}
