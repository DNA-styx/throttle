<?php

namespace App\Security;

use App\Entity\User;
use App\Runtime\AuthTokenManager;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AuthMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AuthTokenManager $authTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $fromAddress,
    ) {
    }

    public function sendVerification(User $user, string $email): void
    {
        $token = $this->authTokenManager->createEmailVerificationToken($user, $email);
        $url = $this->urlGenerator->generate('register_verify', [
            'token' => $token['token'],
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $emailMessage = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Throttle'))
            ->to($email)
            ->subject('Your Throttle email confirmation link')
            ->htmlTemplate('security/emails/verify_email.html.twig')
            ->textTemplate('security/emails/verify_email.txt.twig')
            ->context([
                'user' => $user,
                'actionUrl' => $url,
                'expiresAt' => $token['expires_at'],
            ]);

        $this->mailer->send($emailMessage);
    }

    public function sendLoginLink(User $user, string $email): void
    {
        $token = $this->authTokenManager->createEmailLoginToken($user, $email);
        $url = $this->urlGenerator->generate('login_email_link_verify', [
            'token' => $token['token'],
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $emailMessage = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Throttle'))
            ->to($email)
            ->subject('Your Throttle sign-in link')
            ->htmlTemplate('security/emails/login_link.html.twig')
            ->textTemplate('security/emails/login_link.txt.twig')
            ->context([
                'user' => $user,
                'actionUrl' => $url,
                'expiresAt' => $token['expires_at'],
            ]);

        $this->mailer->send($emailMessage);
    }

    public function sendPasswordReset(User $user, string $email): void
    {
        $token = $this->authTokenManager->createPasswordResetToken($user, $email);
        $url = $this->urlGenerator->generate('password_reset_confirm', [
            'token' => $token['token'],
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $emailMessage = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Throttle'))
            ->to($email)
            ->subject('Reset your Throttle password')
            ->htmlTemplate('security/emails/password_reset.html.twig')
            ->textTemplate('security/emails/password_reset.txt.twig')
            ->context([
                'user' => $user,
                'actionUrl' => $url,
                'expiresAt' => $token['expires_at'],
            ]);

        $this->mailer->send($emailMessage);
    }

    public function sendDiagnostic(string $email, string $baseUrl): void
    {
        $sentAt = new \DateTimeImmutable();

        $emailMessage = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Throttle'))
            ->to($email)
            ->subject('Throttle mail delivery test')
            ->html(sprintf(
                '<p>This is a test email from <strong>Throttle</strong>.</p><p><strong>Sent at:</strong> %s UTC<br><strong>Base URL:</strong> %s<br><strong>From:</strong> %s</p><p>If you received this message, SMTP delivery from Throttle is working.</p>',
                htmlspecialchars($sentAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($baseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($this->fromAddress, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ))
            ->text(sprintf(
                "This is a test email from Throttle.\n\nSent at: %s UTC\nBase URL: %s\nFrom: %s\n\nIf you received this message, SMTP delivery from Throttle is working.\n",
                $sentAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $baseUrl,
                $this->fromAddress
            ));

        $this->mailer->send($emailMessage);
    }
}
