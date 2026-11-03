<?php

declare(strict_types=1);

namespace App\Controller;

use App\Branding\BrandingService;
use App\Branding\LogoStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Public application logos for the login page. */
final class BrandingController
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly LogoStorage $storage,
    ) {
    }

    /** URLs carry ?v=<file name> (content hash), so the response can be cached forever. */
    #[Route('/branding/{clientId}/logo', name: 'branding_logo', methods: ['GET'])]
    public function logo(string $clientId): Response
    {
        $branding = $this->branding->find($clientId);
        $file = $branding?->getLogoFile();
        if (null === $file || !is_file($this->storage->path($file))) {
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($this->storage->path($file), 200, [
            'Content-Type' => (string) $branding->getLogoMime(),
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened directly must not run scripts; inside <img> it never does.
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox",
        ]);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }
}
