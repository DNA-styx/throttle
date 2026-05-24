<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ExternalAccountRepository;
use App\Repository\UserRepository;
use App\Runtime\AuthEnvironment;
use App\Runtime\AuthSettings;
use App\Runtime\AuthTokenManager;
use App\Security\AuthMailer;
use App\Security\SocialLinkManager;
use App\Security\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, AuthSettings $authSettings, AuthEnvironment $authEnvironment): Response
    {
        if ($this->isGranted(AuthenticatedVoter::IS_AUTHENTICATED)) {
            return $this->redirectToRoute('dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $return = $request->query->get('return', '/');
        if (!is_string($return) || $return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            $return = '/';
        }

        return $this->render('security/login.html.twig', [
            'error' => $error,
            'return' => $return,
            'auth_methods' => $authSettings->all(),
            'mailerConfigured' => $authEnvironment->isMailerConfigured(),
            'mailerNotice' => $authEnvironment->mailerNotice(),
            'discordConfigured' => $authEnvironment->isDiscordConfigured(),
            'discordNotice' => $authEnvironment->discordNotice(),
            'discordCallbackUrl' => $this->generateUrl('login_discord', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/login/password', name: 'login_password', methods: ['POST'])]
    public function password(): Response
    {
        throw new \LogicException('unreachable');
    }

    #[Route('/login/token', name: 'login_token', methods: ['POST'])]
    public function token(): Response
    {
        throw new \LogicException('unreachable');
    }

    #[Route('/login/email-link/request', name: 'login_email_link_request', methods: ['POST'])]
    public function requestEmailLink(Request $request, AuthSettings $authSettings, AuthEnvironment $authEnvironment, UserManager $userManager, AuthMailer $authMailer): Response
    {
        if (!$authSettings->isEnabled('email_login_link')) {
            return new Response('Email login links are disabled.', Response::HTTP_FORBIDDEN);
        }
        if (!$this->isCsrfTokenValid('login-email-link-request', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }
        if (!$authEnvironment->isMailerConfigured()) {
            $this->addFlash('danger', $authEnvironment->mailerNotice());

            return $this->redirectToRoute('login');
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        if ($email !== '') {
            $user = $userManager->findByEmail($email);
            if ($user instanceof User && $user->isEmailVerified()) {
                $authMailer->sendLoginLink($user, $email);
            }
        }

        $this->addFlash('success', 'If that email exists and is confirmed, a sign-in link has been sent. If you do not see it, check the Spam folder too.');

        return $this->redirectToRoute('login');
    }

    #[Route('/login/email-link/verify', name: 'login_email_link_verify', methods: ['GET'])]
    public function loginEmailLinkVerify(): Response
    {
        throw new \LogicException('unreachable');
    }

    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request, AuthSettings $authSettings, AuthEnvironment $authEnvironment, UserManager $userManager, UserPasswordHasherInterface $passwordHasher, AuthMailer $authMailer, EntityManagerInterface $entityManager): Response
    {
        if (!$authSettings->isEnabled('password_registration')) {
            return new Response('Registration is disabled.', Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('GET')) {
            return $this->redirectToRoute('login');
        }
        if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }
        if (!$authEnvironment->isMailerConfigured()) {
            $this->addFlash('danger', $authEnvironment->mailerNotice());

            return $this->redirectToRoute('login');
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        $confirm = (string) $request->request->get('password_confirm', '');
        $displayName = '';
        $atPosition = mb_strrpos($email, '@');
        if ($atPosition !== false) {
            $displayName = mb_substr($email, 0, $atPosition);
        }

        if ($email === '' || $password === '') {
            $this->addFlash('danger', 'Fill in email and password.');

            return $this->redirectToRoute('login');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('danger', 'Enter a valid email address.');

            return $this->redirectToRoute('login');
        }

        if ($password !== $confirm) {
            $this->addFlash('danger', 'Password confirmation does not match.');

            return $this->redirectToRoute('login');
        }

        if (strlen($password) < 8) {
            $this->addFlash('danger', 'Password must be at least 8 characters long.');

            return $this->redirectToRoute('login');
        }

        if ($userManager->findByEmail($email) instanceof User) {
            $this->addFlash('danger', 'This email is already in use.');

            return $this->redirectToRoute('login');
        }

        $user = $userManager->createLocalUser(null, $email, $displayName);
        $user->setPasswordHash($passwordHasher->hashPassword($user, $password));
        $entityManager->flush();
        $authMailer->sendVerification($user, $email);

        $this->addFlash('success', 'Registration created. Check your email and confirm it before signing in. If nothing arrives, look in the Spam folder too.');

        return $this->redirectToRoute('login');
    }

    #[Route('/register/verify', name: 'register_verify', methods: ['GET'])]
    public function verifyRegistration(Request $request, AuthTokenManager $authTokenManager, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $token = trim((string) $request->query->get('token', ''));
        $consumed = $token !== '' ? $authTokenManager->consumeToken($token, AuthTokenManager::PURPOSE_EMAIL_VERIFY) : null;
        if ($consumed === null) {
            $this->addFlash('danger', 'This verification link is invalid or expired.');

            return $this->redirectToRoute('login');
        }

        $user = $userRepository->find($consumed['user_id']);
        if ($user instanceof User) {
            if ($user->getContactEmail() === null || !hash_equals($user->getContactEmail(), (string) ($consumed['email'] ?? ''))) {
                $this->addFlash('danger', 'This verification link is no longer valid for the current email address.');

                return $this->redirectToRoute('login');
            }

            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        $this->addFlash('success', 'Email confirmed.');

        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('profile');
        }

        return $this->redirectToRoute('login');
    }

    #[Route('/register/resend-verification', name: 'register_resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request, AuthEnvironment $authEnvironment, UserManager $userManager, AuthMailer $authMailer): Response
    {
        $return = $this->resolveReturnPath($request);
        if (!$this->isCsrfTokenValid('register-resend', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        if (!$authEnvironment->isMailerConfigured()) {
            $this->addFlash('danger', $authEnvironment->mailerNotice());

            return $this->redirect($return);
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        if ($email !== '') {
            $user = $userManager->findByEmail($email);
            if ($user instanceof User && !$user->isEmailVerified()) {
                $authMailer->sendVerification($user, $email);
            }
        }

        $this->addFlash('success', 'If that account exists and still needs verification, a new email has been sent. If you do not see it, check the Spam folder too.');

        return $this->redirect($return);
    }

    #[Route('/password/reset', name: 'password_reset_request', methods: ['GET', 'POST'])]
    public function passwordReset(Request $request, AuthSettings $authSettings, AuthEnvironment $authEnvironment, UserManager $userManager, AuthMailer $authMailer): Response
    {
        if (!$authSettings->isEnabled('password_reset')) {
            return new Response('Password reset is disabled.', Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('GET')) {
            return $this->render('security/password_reset_request.html.twig', [
                'mailerConfigured' => $authEnvironment->isMailerConfigured(),
                'mailerNotice' => $authEnvironment->mailerNotice(),
            ]);
        }

        if (!$authEnvironment->isMailerConfigured()) {
            $this->addFlash('danger', $authEnvironment->mailerNotice());

            return $this->redirectToRoute('password_reset_request');
        }

        if (!$this->isCsrfTokenValid('password-reset-request', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        if ($email !== '') {
            $user = $userManager->findByEmail($email);
            if ($user instanceof User && $user->isEmailVerified()) {
                $authMailer->sendPasswordReset($user, $email);
            }
        }

        $this->addFlash('success', 'If that email exists and is confirmed, a password reset link has been sent. If you do not see it, check the Spam folder too.');

        return $this->redirectToRoute('login');
    }

    #[Route('/password/reset/confirm', name: 'password_reset_confirm', methods: ['GET', 'POST'])]
    public function passwordResetConfirm(Request $request, AuthSettings $authSettings, AuthTokenManager $authTokenManager, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        if (!$authSettings->isEnabled('password_reset')) {
            return new Response('Password reset is disabled.', Response::HTTP_FORBIDDEN);
        }

        $token = trim((string) ($request->isMethod('POST') ? $request->request->get('token', '') : $request->query->get('token', '')));
        $tokenData = $token !== '' ? $authTokenManager->findValidToken($token, AuthTokenManager::PURPOSE_PASSWORD_RESET) : null;
        if ($tokenData === null) {
            $this->addFlash('danger', 'This password reset link is invalid or expired.');

            return $this->redirectToRoute('login');
        }

        if ($request->isMethod('GET')) {
            return $this->render('security/password_reset_confirm.html.twig', [
                'token' => $token,
            ]);
        }

        if (!$this->isCsrfTokenValid('password-reset-confirm', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $password = (string) $request->request->get('password', '');
        $confirm = (string) $request->request->get('password_confirm', '');
        if ($password === '' || strlen($password) < 8) {
            $this->addFlash('danger', 'Password must be at least 8 characters long.');

            return $this->render('security/password_reset_confirm.html.twig', ['token' => $token]);
        }

        if ($password !== $confirm) {
            $this->addFlash('danger', 'Password confirmation does not match.');

            return $this->render('security/password_reset_confirm.html.twig', ['token' => $token]);
        }

        $consumed = $authTokenManager->consumeToken($token, AuthTokenManager::PURPOSE_PASSWORD_RESET);
        if ($consumed === null) {
            $this->addFlash('danger', 'This password reset link is invalid or expired.');

            return $this->redirectToRoute('login');
        }

        $user = $userRepository->find($consumed['user_id']);
        if ($user instanceof User) {
            if ($user->getContactEmail() === null || !hash_equals($user->getContactEmail(), (string) ($consumed['email'] ?? '')) || !$user->isEmailVerified()) {
                $this->addFlash('danger', 'This password reset link is invalid or expired.');

                return $this->redirectToRoute('login');
            }

            $user->setPasswordHash($passwordHasher->hashPassword($user, $password));
            $entityManager->flush();
        }

        $this->addFlash('success', 'Password updated. You can now sign in.');

        return $this->redirectToRoute('login');
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): Response
    {
        throw new \LogicException('unreachable');
    }

    #[Route('/login/link', name: 'login_link')]
    public function link(): Response
    {
        throw new \LogicException('unreachable');
    }

    #[Route('/login/steam', name: 'login_steam')]
    public function steam(): Response
    {
        throw new \LogicException('unreachable');
    }

    #[Route('/login/discord', name: 'login_discord')]
    public function discord(): Response
    {
        throw new \LogicException('unreachable');
    }

    private function resolveReturnPath(Request $request): string
    {
        $return = $request->request->get('return', '/login');
        if (!is_string($return) || $return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            return '/login';
        }

        return $return;
    }
}
