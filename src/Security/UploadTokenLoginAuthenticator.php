<?php

namespace App\Security;

use App\Entity\User;
use App\Runtime\AuthSettings;
use App\Repository\UserRepository;
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
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class UploadTokenLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuthSettings $authSettings,
        private readonly AuthRedirector $authRedirector,
        private readonly UserAccessManager $userAccessManager,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'login_token';
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->authSettings->isEnabled('token_login')) {
            throw new CustomUserMessageAuthenticationException('Token login is currently disabled.');
        }

        $token = trim((string) $request->request->get('token', ''));
        if ($token === '') {
            throw new AuthenticationCredentialsNotFoundException();
        }

        return new SelfValidatingPassport(
            new UserBadge($token, function (string $identifier): User {
                $user = $this->userRepository->findOneBy(['uploadToken' => $identifier]);
                if (!$user instanceof User) {
                    throw new CustomUserMessageAuthenticationException('Invalid upload token.');
                }
                if ($this->userAccessManager->isInteractiveLoginBlocked($user)) {
                    throw new CustomUserMessageAuthenticationException('This account is blocked from signing in.');
                }
                if ($this->userAccessManager->isUploadTokenBlocked($user)) {
                    throw new CustomUserMessageAuthenticationException('This upload token is currently blocked.');
                }

                if (!$user->isEmailVerified() && $user->getContactEmail() !== null && count($user->getExternalAccounts()) === 1) {
                    throw new CustomUserMessageAuthenticationException('Confirm your email before using token login.');
                }

                return $user;
            }),
            [
                new RememberMeBadge(),
                new CsrfTokenBadge('login-token', (string) $request->request->get('_token')),
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
