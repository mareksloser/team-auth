<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Admin\AdminException;
use App\Audit\AuditLogger;
use App\Entity\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Turning two-factor authentication on and off. Setup is two-step: a secret is generated and
 * kept in the session until the user proves (with a first code) that the authenticator app has it.
 */
final class TwoFactorSetup
{
    private const SESSION_KEY = 'totp_setup_secret';

    public function __construct(
        private readonly TotpAuthenticatorInterface $totp,
        private readonly TotpSecretCipher $cipher,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwords,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Secret for the setup in progress (created on first call, kept across page reloads). */
    public function pendingSecret(SessionInterface $session): string
    {
        $secret = $session->get(self::SESSION_KEY);
        if (!\is_string($secret) || '' === $secret) {
            $secret = $this->totp->generateSecret();
            $session->set(self::SESSION_KEY, $secret);
        }

        return $secret;
    }

    /** QR code as an SVG data URI, rendered locally: the secret never leaves the server. */
    public function qrCodeDataUri(User $user, string $secret): string
    {
        $content = $this->totp->getQRContent(new PendingTotp($user->getEmail(), $secret));
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd())))->writeString($content);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * @return list<string> the backup codes, shown to the user once
     */
    public function confirm(User $user, SessionInterface $session, string $code): array
    {
        if ($user->isTotpAuthenticationEnabled()) {
            throw new AdminException('Dvoufázové ověření už je zapnuté.');
        }
        $secret = $this->pendingSecret($session);
        if (!$this->totp->checkCode(new PendingTotp($user->getEmail(), $secret), self::digits($code))) {
            throw new AdminException('Kód nesouhlasí. Zkontroluj, že máš v telefonu správný čas, a zadej aktuální kód.');
        }

        $codes = BackupCodes::generate();
        $user->enableTotp($this->cipher->encrypt($secret), $secret, array_map(BackupCodes::hash(...), $codes));
        $this->em->flush();
        $session->remove(self::SESSION_KEY);
        $this->audit->log('2fa.enable', $user->getUuid());

        return $codes;
    }

    /** @return list<string> */
    public function regenerateBackupCodes(User $user, string $password): array
    {
        $this->assertPassword($user, $password);
        if (!$user->isTotpAuthenticationEnabled()) {
            throw new AdminException('Dvoufázové ověření není zapnuté.');
        }
        $codes = BackupCodes::generate();
        $user->replaceBackupCodes(array_map(BackupCodes::hash(...), $codes));
        $this->em->flush();
        $this->audit->log('2fa.backup_codes', $user->getUuid());

        return $codes;
    }

    /** The user turns it off (needs the password, so a hijacked session alone can't). */
    public function disable(User $user, string $password): void
    {
        $this->assertPassword($user, $password);
        $user->disableTotp();
        $this->em->flush();
        $this->audit->log('2fa.disable', $user->getUuid());
    }

    /** An admin turns it off for someone who lost their phone and backup codes. */
    public function disableByAdmin(User $user, User $admin): void
    {
        $user->disableTotp();
        $this->em->flush();
        $this->audit->log('admin.2fa_disable', $admin->getUuid(), null, ['target' => $user->getUuid()]);
    }

    private function assertPassword(User $user, string $password): void
    {
        if (!$this->passwords->isPasswordValid($user, $password)) {
            throw new AdminException('Heslo není správné.');
        }
    }

    private static function digits(string $code): string
    {
        return (string) preg_replace('/\D+/', '', $code);
    }
}
