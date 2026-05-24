<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Runtime\AuthSettings;
use App\Runtime\AuthTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class EmailLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly AuthTokenManager $authTokenManager,
        private readonly UserRepository $userRepository,
        private readonly AuthSettings $authSettings,
        private readonly AuthRedirector $authRedirector,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'login_email_link_verify';
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->authSettings->isEnabled('email_login_link')) {
            throw new CustomUserMessageAuthenticationException('Email login links are currently disabled.');
        }

        $token = trim((string) $request->query->get('token', ''));
        if ($token === '') {
            throw new AuthenticationCredentialsNotFoundException();
        }

        return new SelfValidatingPassport(
            new UserBadge($token, function (string $identifier): User {
                $consumed = $this->authTokenManager->consumeToken($identifier, AuthTokenManager::PURPOSE_EMAIL_LOGIN);
                if ($consumed === null) {
                    throw new CustomUserMessageAuthenticationException('This login link is invalid or expired.');
                }

                $user = $this->userRepository->find($consumed['user_id']);
                if (!$user instanceof User) {
                    throw new CustomUserMessageAuthenticationException('This login link is invalid or expired.');
                }

                if (!$user->isEmailVerified() || $user->getContactEmail() === null || !hash_equals($user->getContactEmail(), (string) ($consumed['email'] ?? ''))) {
                    throw new CustomUserMessageAuthenticationException('This login link is invalid or expired.');
                }

                return $user;
            }),
            [new RememberMeBadge()],
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
