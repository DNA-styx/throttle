<?php

namespace App\Controller;

use App\Entity\User;
use App\Legacy\LegacyBridgeFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/share')]
#[IsGranted(User::ROLE_USER)]
class LegacySharingController extends AbstractController
{
    use LegacyResponseTrait;

    #[Route('', name: 'share', methods: ['GET'])]
    public function index(Request $request, LegacyBridgeFactory $legacyBridgeFactory): Response
    {
        return $this->legacyResponse((new \Throttle\Sharing())->share($legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/invite', name: 'share_invite', methods: ['GET'])]
    public function invite(Request $request, LegacyBridgeFactory $legacyBridgeFactory): Response
    {
        return $this->legacyResponse((new \Throttle\Sharing())->invite($legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/invite', name: 'share_invite_post', methods: ['POST'])]
    public function invitePost(Request $request, LegacyBridgeFactory $legacyBridgeFactory): Response
    {
        return $this->legacyResponse((new \Throttle\Sharing())->invite_post($legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/accept', name: 'share_accept', methods: ['POST'])]
    public function accept(Request $request, LegacyBridgeFactory $legacyBridgeFactory): Response
    {
        return $this->legacyResponse((new \Throttle\Sharing())->accept($legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/revoke', name: 'share_revoke', methods: ['POST'])]
    public function revoke(Request $request, LegacyBridgeFactory $legacyBridgeFactory): Response
    {
        return $this->legacyResponse((new \Throttle\Sharing())->revoke($legacyBridgeFactory->createHttp($request)));
    }
}
