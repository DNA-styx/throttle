<?php

namespace App\Controller;

use App\Legacy\LegacyBridgeFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LegacyHomeController extends AbstractController
{
    use LegacyResponseTrait;

    private LegacyBridgeFactory $legacyBridgeFactory;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory)
    {
        $this->legacyBridgeFactory = $legacyBridgeFactory;
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->legacyResponse((new \Throttle\Home())->index($this->legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/dashboard/{offset}', name: 'dashboard', methods: ['GET'], requirements: ['offset' => '\d+'], defaults: ['offset' => null])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(Request $request, ?int $offset): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->dashboard($this->legacyBridgeFactory->createHttp($request), $offset));
    }
}
