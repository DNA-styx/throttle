<?php

namespace App\Security;

use App\Entity\User;
use App\Runtime\AuthSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class LocalLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserManager $userManager,
        private readonly AuthSettings $authSettings,
        private readonly AuthRedirector $authRedirector,
        private readonly UserAccessManager $userAccessManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'login_password';
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->authSettings->isEnabled('password_login')) {
            throw new CustomUserMessageAuthenticationException('Password login is currently disabled.');
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        if ($email === '' || $password === '') {
            throw new AuthenticationCredentialsNotFoundException();
        }

        return new Passport(
            new UserBadge($email, function (string $identifier): User {
                $user = $this->userManager->findByEmail($identifier);
                if (!$user instanceof User) {
                    // Keep old local-only accounts usable while the UI is email-first.
                    $user = $this->userManager->findByLogin($identifier);
                }
                if (!$user instanceof User || !$user->hasPassword()) {
                    throw new CustomUserMessageAuthenticationException('Invalid email or password.');
                }
                if ($this->userAccessManager->isInteractiveLoginBlocked($user)) {
                    throw new CustomUserMessageAuthenticationException('This account is blocked from signing in.');
                }

                if (!$user->isEmailVerified() && $user->getContactEmail() !== null && count($user->getExternalAccounts()) === 1) {
                    throw new CustomUserMessageAuthenticationException('Confirm your email before signing in.');
                }

                return $user;
            }),
            new PasswordCredentials($password),
            [
                new RememberMeBadge(),
                new CsrfTokenBadge('login-password', (string) $request->request->get('_token')),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->authRedirector->onSuccess($request);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->authRedirector->onFailure($request, $exception->getMessageKey());
    }
}
