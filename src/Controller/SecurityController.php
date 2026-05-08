<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted(AuthenticatedVoter::IS_AUTHENTICATED)) {
            return $this->redirectToRoute('index');
        }

        $loginLinkSent = $request->getSession()->remove('login_link_sent') !== null;

        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            'login_link_sent' => $loginLinkSent,
            'error' => $error,
        ]);
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

}
