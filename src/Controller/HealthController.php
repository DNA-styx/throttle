<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
class HealthController extends AbstractController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function index(Connection $connection, KernelInterface $kernel): Response
    {
        $root = $kernel->getProjectDir();
        $checks = [];

        $checks[] = $this->check('PHP version', version_compare(PHP_VERSION, '8.4.0', '>='), PHP_VERSION);

        try {
            $connection->fetchOne('SELECT 1');
            $checks[] = $this->check('Database', true, 'Connected');
        } catch (\Throwable $e) {
            $checks[] = $this->check('Database', false, $e->getMessage());
        }

        foreach (['var/cache', 'var/log', 'cache', 'dumps', 'symbols'] as $path) {
            $fullPath = $root . '/' . $path;
            $checks[] = $this->check($path . ' writable', is_dir($fullPath) && is_writable($fullPath), $fullPath);
        }

        foreach (['carburetor', 'minidump_stackwalk', 'dump_syms', 'breakpad_moduleid', 'nm'] as $binary) {
            $path = $root . '/bin/' . $binary;
            $checks[] = $this->check('bin/' . $binary, is_file($path) && is_executable($path), $path);
        }

        $queue = [
            'pending' => (int) $connection->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 0'),
            'failed' => (int) $connection->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 1 AND failed = 1'),
            'processed_today' => (int) $connection->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 1 AND timestamp >= CURDATE()'),
            'latest_processed' => $connection->fetchOne('SELECT MAX(created_at) FROM crash_processing_log'),
        ];

        return $this->render('health/index.html.twig', [
            'checks' => $checks,
            'queue' => $queue,
            'healthy' => !in_array(false, array_column($checks, 'ok'), true),
        ]);
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
