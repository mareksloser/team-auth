<?php

declare(strict_types=1);

namespace App\Twig;

use App\Branding\LoginBranding;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** login_branding() for templates rendered by other bundles (e.g. the two-factor form). */
final class LoginBrandingExtension extends AbstractExtension
{
    public function __construct(private readonly LoginBranding $branding)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('login_branding', $this->branding->current(...))];
    }
}
