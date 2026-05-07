<?php

namespace App\Controller;

use App\Legacy\LegacyBridgeFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class LegacySymbolsController extends AbstractController
{
    use LegacyResponseTrait;

    private LegacyBridgeFactory $legacyBridgeFactory;
    private AuthorizationCheckerInterface $authorizationChecker;
    private string $symbolUploadToken;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory, AuthorizationCheckerInterface $authorizationChecker, string $symbolUploadToken)
    {
        $this->legacyBridgeFactory = $legacyBridgeFactory;
        $this->authorizationChecker = $authorizationChecker;
        $this->symbolUploadToken = $symbolUploadToken;
    }

    #[Route('/symbols/submit', name: 'symbols_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        if (!$this->canUploadSymbols($request)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Symbols())->submit($this->legacyBridgeFactory->createHttp($request)));
    }

    private function canUploadSymbols(Request $request): bool
    {
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return true;
        }

        if ($this->symbolUploadToken === '') {
            return false;
        }

        $provided = $request->headers->get('X-Symbol-Upload-Token');
        if (!is_string($provided) || $provided === '') {
            $provided = $request->headers->get('Authorization');
            if (is_string($provided) && preg_match('/^Bearer\s+(.+)$/', $provided, $matches) === 1) {
                $provided = $matches[1];
            }
        }

        if (!is_string($provided) || $provided === '') {
            $provided = $request->request->get('token');
        }

        if (!is_string($provided) || $provided === '') {
            $provided = $request->query->get('token');
        }

        return is_string($provided) && hash_equals($this->symbolUploadToken, $provided);
    }
}
