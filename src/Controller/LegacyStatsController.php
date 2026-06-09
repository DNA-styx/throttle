<?php

namespace App\Controller;

use App\Legacy\LegacyBridgeFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegacyStatsController extends AbstractController
{
    use LegacyResponseTrait;

    private LegacyBridgeFactory $legacyBridgeFactory;
    private Connection $connection;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory, Connection $connection)
    {
        $this->legacyBridgeFactory = $legacyBridgeFactory;
        $this->connection = $connection;
    }

    #[Route('/stats/today', name: 'stats_today', methods: ['GET'])]
    public function today(Request $request): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->today($this->legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/stats/lifetime', name: 'stats_lifetime', methods: ['GET'])]
    public function lifetime(Request $request): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->lifetime($this->legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/stats/unique', name: 'stats_unique', methods: ['GET'])]
    public function unique(Request $request): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->unique($this->legacyBridgeFactory->createHttp($request)));
    }

    #[Route('/stats/users', name: 'stats_users', methods: ['GET'])]
    public function users(): Response
    {
        return new Response((string) ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM `user`')));
    }

    #[Route('/stats/daily/{module}/{function}', name: 'stats_daily', methods: ['GET'], defaults: ['module' => null, 'function' => null])]
    public function daily(Request $request, ?string $module, ?string $function): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->daily($this->legacyBridgeFactory->createHttp($request), $module, $function));
    }

    #[Route('/stats/hourly/{module}/{function}', name: 'stats_hourly', methods: ['GET'], defaults: ['module' => null, 'function' => null])]
    public function hourly(Request $request, ?string $module, ?string $function): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->hourly($this->legacyBridgeFactory->createHttp($request), $module, $function));
    }

    #[Route('/stats/top/{module}/{function}', name: 'stats_top', methods: ['GET'], defaults: ['module' => null, 'function' => null])]
    public function top(Request $request, ?string $module, ?string $function): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->top($this->legacyBridgeFactory->createHttp($request), $module, $function));
    }

    #[Route('/stats/latest/{module}/{function}', name: 'stats_latest', methods: ['GET'], defaults: ['module' => null, 'function' => null])]
    public function latest(Request $request, ?string $module, ?string $function): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->latest($this->legacyBridgeFactory->createHttp($request), $module, $function));
    }

    #[Route('/stats/{module}/{function}', name: 'stats', methods: ['GET'], defaults: ['module' => null, 'function' => null])]
    public function index(Request $request, ?string $module, ?string $function): Response
    {
        return $this->legacyResponse((new \Throttle\Stats())->index($this->legacyBridgeFactory->createHttp($request), $module, $function));
    }
}
