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
 * Napojení OIDC vrstvy na league/oauth2-server-bundle.
 * Služby hledáme podle třídy, ne podle ID, aby to přežilo změny ID mezi verzemi bundlu.
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
                // sub v tokenech = UUID uživatele, ne e-mail
                BundleUserConverter::class => $definition->setClass(UserConverter::class),
                default => null,
            };
        }

        if (null === $bundleRepositoryId || null === $authorizationServer) {
            throw new \LogicException('Nenalezen AuthorizationServer nebo AccessTokenRepository z league/oauth2-server-bundle.');
        }

        // Náš repozitář vydává JWT s claimy roles/teams a deleguje ukládání na původní.
        $container->getDefinition(AccessTokenRepository::class)
            ->setArgument('$inner', new Reference($bundleRepositoryId));

        $authorizationServer->replaceArgument(1, new Reference(AccessTokenRepository::class));
        // 6. argument konstruktoru = ResponseTypeInterface → přidá id_token
        $authorizationServer->setArgument(5, new Reference(IdTokenResponse::class));
    }
}
