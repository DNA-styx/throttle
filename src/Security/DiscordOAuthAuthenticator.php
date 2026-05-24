<?php

namespace App\Security;

use App\Runtime\AuthSettings;
use App\Runtime\AuthEnvironment;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Wohali\OAuth2\Client\Provider\DiscordResourceOwner;

class DiscordOAuthAuthenticator extends OAuth2Authenticator
{
    private const EXTERNAL_ACCOUNT_KIND = 'discord';

    private ClientRegistry $clientRegistry;
    private UserManager $userManager;
    private AuthSettings $authSettings;
    private AuthEnvironment $authEnvironment;
    private SocialLinkManager $socialLinkManager;
    private Security $security;
    private AuthenticationSuccessHandlerInterface $successHandler;
    private AuthenticationFailureHandlerInterface $failureHandler;

    public function __construct(ClientRegistry $clientRegistry, UserManager $userManager, AuthSettings $authSettings, AuthEnvironment $authEnvironment, SocialLinkManager $socialLinkManager, Security $security, AuthenticationSuccessHandlerInterface $successHandler, AuthenticationFailureHandlerInterface $failureHandler)
    {
        $this->clientRegistry = $clientRegistry;
        $this->userManager = $userManager;
        $this->authSettings = $authSettings;
        $this->authEnvironment = $authEnvironment;
        $this->socialLinkManager = $socialLinkManager;
        $this->security = $security;
        $this->successHandler = $successHandler;
        $this->failureHandler = $failureHandler;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'login_discord';
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(Request $request): Passport
    {
        $linkContext = $this->socialLinkManager->current();
        if ($linkContext === null && !$this->authSettings->isEnabled('discord')) {
            throw new CustomUserMessageAuthenticationException('Discord login is currently disabled.');
        }
        if (!$this->authEnvironment->isDiscordConfigured()) {
            throw new CustomUserMessageAuthenticationException('Discord login is not configured yet. Ask an administrator to set the Discord OAuth credentials and callback URL.');
        }

        if (!$request->query->has('state')) {
            throw new AuthenticationCredentialsNotFoundException();
        }

        $client = $this->clientRegistry->getClient('discord');
        $accessToken = $this->fetchAccessToken($client);

        /** @var DiscordResourceOwner $resourceOwner */
        $resourceOwner = $client->fetchUserFromToken($accessToken);

        /** @var array{id: string, username: string, discriminator: string} $userInfo */
        $userInfo = $resourceOwner->toArray();

        $displayName = sprintf('%s#%s', $userInfo['username'], $userInfo['discriminator']);

        if ($linkContext !== null && $linkContext['kind'] === self::EXTERNAL_ACCOUNT_KIND) {
            $currentUser = $this->security->getUser();
            if (!$currentUser instanceof \App\Entity\User) {
                throw new CustomUserMessageAuthenticationException('You must be signed in to link Discord.');
            }

            $user = $currentUser;
            $this->userManager->linkExternalAccount($user, self::EXTERNAL_ACCOUNT_KIND, $userInfo['id'], $displayName);
            $this->socialLinkManager->clear();
        } else {
            $user = $this->userManager->findOrCreateUserForExternalAccount(
                self::EXTERNAL_ACCOUNT_KIND, $userInfo['id'], $displayName, $userInfo['username']);
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), function () use ($user) {
            return $user;
        }), [
            new RememberMeBadge(),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    /**
     * {@inheritDoc}
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof AuthenticationCredentialsNotFoundException) {
            return $this->clientRegistry
                ->getClient('discord')
                ->redirect(['identify'], []);
        }

        return $this->failureHandler->onAuthenticationFailure($request, $exception);
    }
}
