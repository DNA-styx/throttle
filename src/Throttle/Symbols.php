<?php

namespace Throttle;

use Silex\Application;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Symbols
{
    public function submit(Application $app)
    {
        $uploadSettings = $app['config']['upload-settings'] ?? ['streaming_symbols_enabled' => true];
        if (($uploadSettings['streaming_symbols_enabled'] ?? true) === true) {
            return $this->submitStreaming($app);
        }

        return $this->submitBuffered($app);
    }

    private function submitBuffered(Application $app)
    {
        $file = $this->findUploadedSymbolFile($app);
        if ($file instanceof UploadedFile) {
            $data = (string) file_get_contents($file->getPathname());
        } else {
            $data = $app['request']->get('symbol_file');
        }
        if ($data === null || $data === '') {
            $data = $app['request']->getContent();
        }
        if (!is_string($data)) {
            $data = '';
        }

        $app['redis']->hIncrBy('throttle:stats', 'symbols:submitted', 1);
        $app['redis']->hIncrBy('throttle:stats', 'symbols:submitted:bytes', strlen($data));

        $lines = phutil_split_lines($data, false);
        $first_line = $lines[0] ?? '';

        if (!preg_match('/^MODULE (?P<operatingsystem>[^ ]++) (?P<architecture>[^ ]++) (?P<id>[a-fA-F0-9]++) (?P<name>[^\\/\\\\\r\n]++)$/m', $first_line, $info)) {
            $app['monolog']->warning('Invalid symbol file: ' . $first_line);
            $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:invalid', 1);

            return new \Symfony\Component\HttpFoundation\Response('Invalid symbol file', 400);
        }

        if ($info['operatingsystem'] === 'Linux') {
            $functions = 0;

            foreach ($lines as $line) {
                list($type) = explode(' ', $line, 2);

                if ($type === 'STACK') {
                    break;
                }

                if ($type === 'FUNC') {
                    $functions++;
                }
            }

            if ($functions === 0) {
                $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:no-functions', 1);
                return new \Symfony\Component\HttpFoundation\Response('Symbol file had no FUNC records, please update to Accelerator 2.4.3 or later', 400);
            }
        }

        $path = $app['root'] . '/symbols/public/' . $info['name'] . '/' . $info['id'];

        \Filesystem::createDirectory($path, 0755, true);

        $file = $info['name'];
        if (pathinfo($file, PATHINFO_EXTENSION) == 'pdb') {
            $file = substr($file, 0, -4);
        }

        \Filesystem::writeFile($path . '/' . $file . '.sym.gz', gzencode($data));
        $this->markModuleSymbolsPresent($app, $info['name'], $info['id']);

        $app['redis']->hIncrBy('throttle:stats', 'symbols:accepted', 1);
        $this->setUploadInfo($app, $info['name'], $info['id'], strlen($data));

        return $app['twig']->render('submit-symbols.txt.twig', array(
            'module' => $info,
        ));
    }

    private function submitStreaming(Application $app)
    {
        $input = $this->openSymbolInput($app);
        if ($input === null) {
            return new \Symfony\Component\HttpFoundation\Response('Invalid symbol file', 400);
        }

        $handle = $input['handle'];
        $bytes = 0;
        $tempPath = null;
        $gzip = null;

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                $app['monolog']->warning('Invalid symbol file: empty upload');
                $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:invalid', 1);

                return new \Symfony\Component\HttpFoundation\Response('Invalid symbol file', 400);
            }

            $bytes += strlen($firstLine);
            if (!preg_match('/^MODULE (?P<operatingsystem>[^ ]++) (?P<architecture>[^ ]++) (?P<id>[a-fA-F0-9]++) (?P<name>[^\\/\\\\\r\n]++)$/', rtrim($firstLine, "\r\n"), $info)) {
                $app['monolog']->warning('Invalid symbol file: ' . rtrim($firstLine, "\r\n"));
                $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:invalid', 1);

                return new \Symfony\Component\HttpFoundation\Response('Invalid symbol file', 400);
            }

            $path = $app['root'] . '/symbols/public/' . $info['name'] . '/' . $info['id'];
            \Filesystem::createDirectory($path, 0755, true);

            $file = $info['name'];
            if (pathinfo($file, PATHINFO_EXTENSION) == 'pdb') {
                $file = substr($file, 0, -4);
            }

            $targetPath = $path . '/' . $file . '.sym.gz';
            $tempPath = $targetPath . '.tmp.' . bin2hex(random_bytes(6));
            $gzip = gzopen($tempPath, 'wb9');
            if ($gzip === false) {
                throw new \RuntimeException('Could not open symbol file for writing.');
            }

            gzwrite($gzip, $firstLine);
            $functions = 0;
            $checkingFunctions = $info['operatingsystem'] === 'Linux';

            while (($line = fgets($handle)) !== false) {
                $bytes += strlen($line);
                gzwrite($gzip, $line);

                if ($checkingFunctions) {
                    [$type] = explode(' ', $line, 2);
                    $type = rtrim($type, "\r\n");
                    if ($type === 'STACK') {
                        $checkingFunctions = false;
                    } elseif ($type === 'FUNC') {
                        $functions++;
                    }
                }
            }

            if ($info['operatingsystem'] === 'Linux' && $functions === 0) {
                gzclose($gzip);
                $gzip = null;
                if (is_file($tempPath)) {
                    unlink($tempPath);
                }

                $app['redis']->hIncrBy('throttle:stats', 'symbols:rejected:no-functions', 1);
                $this->setUploadInfo($app, $info['name'], $info['id'], $bytes);

                return new \Symfony\Component\HttpFoundation\Response('Symbol file had no FUNC records, please update to Accelerator 2.4.3 or later', 400);
            }

            gzclose($gzip);
            $gzip = null;
            rename($tempPath, $targetPath);

            $app['redis']->hIncrBy('throttle:stats', 'symbols:submitted', 1);
            $app['redis']->hIncrBy('throttle:stats', 'symbols:submitted:bytes', $bytes);
            $this->markModuleSymbolsPresent($app, $info['name'], $info['id']);
            $app['redis']->hIncrBy('throttle:stats', 'symbols:accepted', 1);
            $this->setUploadInfo($app, $info['name'], $info['id'], $bytes);

            return $app['twig']->render('submit-symbols.txt.twig', array(
                'module' => $info,
            ));
        } finally {
            if (is_resource($gzip)) {
                gzclose($gzip);
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($tempPath !== null && is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function markModuleSymbolsPresent(Application $app, string $module, string $identifier): void
    {
        if (!isset($app['db'])) {
            return;
        }

        $updated = $app['db']->executeUpdate(
            'UPDATE module SET present = 1 WHERE name = ? AND identifier = ? AND present = 0',
            array($module, $identifier)
        );

        if ($updated > 0) {
            $app['redis']->hIncrBy('throttle:stats', 'symbols:module-present-updates', $updated);
        }
    }

    private function findUploadedSymbolFile(Application $app): ?UploadedFile
    {
        foreach (array('symbol_file', 'upload_file_symbols', 'upload_file_symbol', 'symbols') as $field) {
            $file = $app['request']->files->get($field);
            if ($file instanceof UploadedFile && $file->isValid() && $file->getSize() > 0) {
                return $file;
            }
        }

        return null;
    }

    private function openSymbolInput(Application $app): ?array
    {
        $file = $this->findUploadedSymbolFile($app);
        if ($file instanceof UploadedFile) {
            $handle = fopen($file->getPathname(), 'rb');

            return is_resource($handle) ? ['handle' => $handle] : null;
        }

        $data = $app['request']->get('symbol_file');
        if (is_string($data) && $data !== '') {
            $handle = fopen('php://temp', 'w+b');
            if (!is_resource($handle)) {
                return null;
            }
            fwrite($handle, $data);
            rewind($handle);

            return ['handle' => $handle];
        }

        if (isset($app['base_request'])) {
            $handle = $app['base_request']->getContent(true);
            return is_resource($handle) ? ['handle' => $handle] : null;
        }

        $handle = $app['request']->getContent(true);
        return is_resource($handle) ? ['handle' => $handle] : null;
    }

    private function setUploadInfo(Application $app, string $module, string $identifier, int $bytes): void
    {
        $info = ['module' => $module, 'identifier' => $identifier, 'bytes' => $bytes];
        if (isset($app['base_request'])) {
            $app['base_request']->attributes->set('_symbol_upload_info', $info);
        }
        $app['request']->attributes->set('_symbol_upload_info', $info);
    }
}

