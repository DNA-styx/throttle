<?php

namespace App\Controller;

use App\Legacy\LegacyBridgeFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegacyCrashController extends AbstractController
{
    use LegacyResponseTrait;

    private LegacyBridgeFactory $legacyBridgeFactory;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory)
    {
        $this->legacyBridgeFactory = $legacyBridgeFactory;
    }

    #[Route('/submit', name: 'submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->submit($this->legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/submit', name: 'submit_get', methods: ['GET'])]
    public function submitGet(Request $request): Response
    {
        $app = $this->legacyBridgeFactory->createHttp($request);

        return new Response($app['twig']->render('error.html.twig', [
            'icon' => 'remove-sign',
            'title' => 'Method Not Allowed',
            'comment' => 'The Accelerator extension must be used to upload crash dumps.',
        ]));
    }

    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function download(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->download($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/view', name: 'view', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function view(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->view($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/logs', name: 'logs', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function logs(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->logs($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/metadata', name: 'metadata', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function metadata(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->metadata($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/console', name: 'console', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function console(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->console($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/error', name: 'error', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function error(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->error($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/carburetor', name: 'carburetor', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function carburetor(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->carburetor($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/carburetor/data', name: 'carburetor_data', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function carburetorData(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->carburetor_data($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/reprocess', name: 'reprocess', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function reprocess(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('reprocess-crash:'.$id, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->reprocess($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function delete(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('delete-crash:'.$id, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->delete($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{crashId}', name: 'details_crashid', methods: ['GET'], requirements: ['crashId' => '[0-9a-zA-Z]{4}(?:-[0-9a-zA-Z]{4}){2}'], priority: -95)]
    public function formattedId(string $crashId): Response
    {
        return $this->redirectToRoute('details', ['id' => strtolower(str_replace('-', '', $crashId))]);
    }

    #[Route('/{uuid}', name: 'details_uuid', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'], priority: -100)]
    public function uuid(string $uuid): Response
    {
        $uuid = substr($uuid, 20, 3).substr($uuid, 24);
        $uuid = str_split($uuid);
        $bid = '';
        for ($i = 0; $i < 15; $i++) {
            $bid .= sprintf('%04b', hexdec($uuid[$i]));
        }
        $bid = str_split($bid, 5);

        $id = '';
        $map = array_merge(range('a', 'z'), range('2', '7'));
        for ($i = 0; $i < 12; $i++) {
            $id .= $map[bindec($bid[$i])];
        }

        return $this->redirectToRoute('details', ['id' => $id]);
    }

    #[Route('/{id}', name: 'details', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -100)]
    public function details(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->details($this->legacyBridgeFactory->createHttp($request), $id));
    }
}
