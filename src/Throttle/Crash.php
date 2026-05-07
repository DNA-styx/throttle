<?php

namespace Throttle;

use Silex\Application;

class Crash
{
    private static function getSafeReturnPath(Application $app, ?string $return, string $fallbackRoute): string
    {
        if (is_string($return) && $return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) {
            $parts = parse_url($return);
            if ($parts !== false && !isset($parts['scheme']) && !isset($parts['host'])) {
                return $return;
            }
        }

        return $app['url_generator']->generate($fallbackRoute);
    }

    private static function toSteamId64(array $parts): ?string
    {
        if (count($parts) !== 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return null;
        }

        $accountId = (((int) $parts[2]) * 2) + (int) $parts[1];

        return (string) (76561197960265728 + $accountId);
    }

    private static function generateId($app)
    {
        for ($i = 0; $i < 10; $i++) {
            $id = \Filesystem::readRandomCharacters(12);
            $path = $app['root'] . '/dumps/' . substr($id, 0, 2);

            if (\Filesystem::pathExists($path . '/' . $id . '.dmp')) {
                continue;
            }

            return array($id, $path);
        }

        throw new \Exception('MINIDUMP COLLISION');
    }

    private static function canUserManage($app, $crash)
    {
        $ownerId = $app['db']->executeQuery('SELECT owner_id FROM crash WHERE crash.id = ?', [$crash])->fetchColumn(0);
        if ($ownerId === false) {
            return null;
        }

        if (!$app['user']) {
            return false;
        }

        if ($app['user']['admin']) {
            return true;
        }

        return $ownerId !== null && in_array((int) $ownerId, $app['user']['owner_ids'], true);
    }

    public static function parsePresubmitSignature($signature)
    {
        $signature = array_reverse(explode('|', $signature));
        $version = (int)array_pop($signature);
        if ($version < 0 || $version > 2) {
            throw new \Exception('bad version');
        }

        $timestamp = time();
        $platform = '';
        $architecture = 'x86';
        if ($version > 1) {
            $timestamp = (int)array_pop($signature);
            $platform = array_pop($signature);
            $architecture = array_pop($signature);
        }

        $crashed = (int)array_pop($signature);
        $crash_reason = array_pop($signature);
        $crash_address = intval(array_pop($signature), 16);
        $requesting_thread = (int)array_pop($signature);

        $modules = [];
        $frames = [];

        while (!empty($signature)) {
            $type = array_pop($signature);
            switch ($type) {
                case 'M':
                    $file = array_pop($signature);
                    if (!strlen($platform)) {
                        switch (pathinfo($file, PATHINFO_EXTENSION)) {
                            case 'pdb':
                                $platform = 'windows';
                                break;
                            case 'dylib':
                                $platform = 'mac';
                                break;
                            case 'so':
                                $platform = 'linux';
                                break;
                        }
                    }
                    $modules[] = (object)[
                        'file' => $file,
                        'identifier' => array_pop($signature),
                    ];
                    break;
                case 'F':
                    $frames[] = (object)[
                        'module' => (int)array_pop($signature),
                        'offset' => intval(array_pop($signature), 16),
                    ];
                    break;
                default:
                    throw new \Exception('unknown field '.$type);
            }
        }

        return (object)compact('timestamp', 'platform', 'architecture', 'crashed', 'crash_reason', 'crash_address', 'requesting_thread', 'modules', 'frames');
    }

    public function presubmit(Application $app, $signature)
    {
        //$app['monolog']->warning('Presubmit: '.$signature);

        try {
            $signature = self::parsePresubmitSignature($signature);
        } catch (\Exception $e) {
            $app['monolog']->warning('Error parsing presubmit: '.$signature, [$e]);

            return 'E|'.$e->getMessage();
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:presubmitted', 1);

        // TODO: Determine whether we want the crash dump...
        $return = 'Y|';
        foreach ($signature->modules as $module) {
            if ($module->identifier === '000000000000000000000000000000000') {
                $app['monolog']->warning('Ignoring module with invalid identifier: '.$module->file);
                $return .= 'N';
                continue;
            }

            // TODO: N query problem...
            $exists = $app['db']->executeQuery('SELECT TRUE FROM module WHERE name = ? AND identifier = ? AND present = 1 LIMIT 1', [$module->file, $module->identifier])->fetchColumn(0);
            $return .= ($exists === false) ? 'Y' : 'N';
        }

        // Stick a random presubmit token on the end for testing.
        $return .= '|'.md5($return);

        return $return;
    }

    public function submit(Application $app)
    {
        //TODO
        //return $app->abort(503);
        //return 'Sorry, crash submission is currently disabled';

        $presubmit = $app['request']->get('CrashSignature');
        if ($presubmit !== null) {
            return $this->presubmit($app, $presubmit);
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:submitted', 1);

        $minidump = $app['request']->files->get('upload_file_minidump');

        if ($minidump === null || !$minidump->isValid() || $minidump->getSize() <= 0) {
            $app['redis']->hIncrBy('throttle:stats', 'crashes:rejected:no-minidump', 1);

            return $app['twig']->render('submit-empty.txt.twig');
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:submitted:bytes', (int) $minidump->getSize());

        list($id, $path) = self::generateId($app);

        $ip = $app['request']->getClientIp();

        $ownerId = null;
        $legacyOwnerId = $app['request']->request->get('UserID');
        if ($legacyOwnerId !== null) {
            $app['request']->request->remove('UserID');

            if ($legacyOwnerId === '0') {
                $legacyOwnerId = null;
            } elseif (stripos($legacyOwnerId, 'STEAM_') === 0) {
                $legacyOwnerId = self::toSteamId64(explode(':', $legacyOwnerId));
            } elseif (!ctype_digit($legacyOwnerId)) {
                $app['monolog']->warning('Bad owner provided in submit', ['id' => $id, 'owner' => $legacyOwnerId]);
                $legacyOwnerId = null;
            }

            if ($legacyOwnerId !== null) {
                $ownerId = $app['owner_resolver']->resolveOwnerIdFromLegacyIdentifier($legacyOwnerId);
            }
        }

        $serverId = $app['request']->request->get('ServerID');
        if ($serverId !== null) {
            $app['request']->request->remove('ServerID');

            if (ctype_digit((string) $serverId)) {
                $server = $app['db']->executeQuery('SELECT id, owner_id FROM server WHERE id = ?', [(int) $serverId])->fetch();
                if ($server !== false) {
                    $serverId = (int) $server['id'];
                    $ownerId = (int) $server['owner_id'];
                } else {
                    $serverId = null;
                }
            } else {
                $serverId = null;
            }
        }

        if ($ownerId !== null) {
            $count = $app['db']->executeQuery('SELECT COUNT(*) AS count FROM crash WHERE owner_id = ? AND ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$ownerId, $ip])->fetchColumn(0);
        } else {
            $count = $app['db']->executeQuery('SELECT COUNT(*) AS count FROM crash WHERE owner_id IS NULL AND ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$ip])->fetchColumn(0);
        }

        if ($count > 12) {
            $app['redis']->hIncrBy('throttle:stats', 'crashes:rejected:rate-limit', 1);

            return $app['twig']->render('submit-reject.txt.twig');
        }

        $metadata = $app['request']->request->all();

        $raw_metadata = null;
        $metadata_file = $app['request']->files->get('upload_file_metadata');
        if ($metadata_file !== null && $metadata_file->isValid() && $metadata_file->getSize() > 0) {
            $raw_metadata = \Filesystem::readFile($metadata_file->getRealPath());

            $has_config = preg_match('/(?<=-------- CONFIG BEGIN --------)[^\\x00]+(?=-------- CONFIG END --------)/i', $raw_metadata, $metadata_config);
            if ($has_config === 1) {
                $metadata_config = trim($metadata_config[0]);
                $metadata_config = phutil_split_lines($metadata_config, false);

                // Merge with the existing metadata, overwrite existing.
                foreach ($metadata_config as $line) {
                    list($key, $value) = array_pad(explode('=', $line, 2), 2, '');

                    $key = trim($key);
                    $value = trim($value);

                    if (strlen($value)) {
                        $metadata[$key] = $value;
                    }
                }
            }

            $has_console = preg_match('/(?<=-------- CONSOLE HISTORY BEGIN --------)[^\\x00]+(?=-------- CONSOLE HISTORY END --------)/i', $raw_metadata, $metadata_console);
            if ($has_console === 1 && strlen(trim($metadata_console[0]))) {
                $metadata['HasConsoleLog'] = true;
            }
        }

        $command_line = null;
        if (isset($metadata['CommandLine'])) {
            $old = mb_substitute_character();
            mb_substitute_character(0xFFFD);

            $command_line = mb_convert_encoding($metadata['CommandLine'], 'UTF-8', 'UTF-8');
            unset($metadata['CommandLine']);

            mb_substitute_character($old);
        }

        // Strip any presubmit token, until we're ready to do something with them
        if (isset($metadata['PresubmitToken'])) {
            unset($metadata['PresubmitToken']);
        }

        $metadata = json_encode($metadata, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES);

        $app['db']->executeUpdate(
            'INSERT INTO crash (id, timestamp, ip, owner_id, server_id, metadata, cmdline) VALUES (?, NOW(), ?, ?, ?, ?, ?)',
            [$id, $ip, $ownerId, $serverId, $metadata, $command_line]
        );

        // Move after it's in the DB, to avoid a race condition with the cleanup code.
        \Filesystem::createDirectory($path, 0755, true);
        $minidump->move($path, $id . '.dmp');

        if ($raw_metadata) {
            $metapath = $path . '/' . $id . '.meta.txt.gz';
            \Filesystem::writeFile($metapath, gzencode($raw_metadata));
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:accepted', 1);

/*
        try {
            $app['queue']->putInTube('carburetor', json_encode(array(
                'id' => $id,
                'owner' => $owner,
                'ip' => $ip,
            )));
        } catch (\Exception $e) {}
*/

        // Special code for handling breakpad-uploaded minidumps.
        // FIXME: This is mainly a hack for testing Electron.
        if ($app['request']->request->get('prod')) {
            $bid = '1000'; // First 2 bits specify UUID variant
            $map = array_merge(range('a', 'z'), range('2', '7'));
            for ($i = 0; $i < 12; $i++) {
                $bid .= sprintf('%05b', array_search($id[$i], $map));
            }
            $bid = str_split($bid, 8);

            $uuid = 'bee0cafe-0000-4000-'; // 4 = UUID version
            for ($i = 0; $i < 8; $i++) {
                $uuid .= sprintf('%02x', bindec($bid[$i]));
                if ($i === 1) $uuid .= '-';
            }

            return $uuid;
        }

        return $app['twig']->render('submit.txt.twig', array(
            'id' => $id,
        ));
    }

    public function details(Application $app, $id)
    {
        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            if ($app['session']->getFlashBag()->get('internal')) {
                $app['session']->getFlashBag()->add('error_crash', 'That Crash ID does not exist.');

                return $app->redirect($app['url_generator']->generate('index'));
            }

            return $app->abort(404);
        }

        $crash = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) AS timestamp, crash.ip AS ip, crash.owner_id AS owner, crash.server_id, crash.metadata, crash.cmdline, crash.thread, crash.processed, crash.failed, crash.stackhash, UNIX_TIMESTAMP(crash.lastview) AS lastview, server_owner.name FROM crash LEFT JOIN server_owner ON server_owner.id = crash.owner_id WHERE crash.id = ?', [$id])->fetch();

        if ($crash['lastview'] === null || (time() - $crash['lastview']) > (60 * 60 * 24)) {
            $app['db']->executeUpdate('UPDATE crash SET lastview = NOW() WHERE id = ?', array($id));
        }

        if ($crash['thread'] == -1) {
            $crash['thread'] = 0;
        }

        $crash['cmdline'] = preg_replace_callback(array_map(function($v) {
            return sprintf('/(?<=%s )[^ ]+/', preg_quote($v));
        }, [
            '+sv_password',
            '+rcon_password',
            '+sv_setsteamaccount',
        ]), function($matches) {
            return str_repeat('*', strlen($matches[0]));
        }, $crash['cmdline']);

        $crash['metadata'] = json_decode($crash['metadata'], true);

        if (isset($crash['metadata']['HasConsoleLog'])) {
            $crash['has_console_log'] = $crash['metadata']['HasConsoleLog'];
            unset($crash['metadata']['HasConsoleLog']);
        } else {
            $crash['has_console_log'] = false;
        }

        if (isset($crash['metadata']['ExtensionBuild'])) {
            unset($crash['metadata']['ExtensionBuild']);
        }

        $crash['dump_available'] = \Filesystem::pathExists($app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp');

        ksort($crash['metadata']);

        $notices = $app['db']->executeQuery('SELECT severity, text FROM crashnotice JOIN notice ON notice.id = crashnotice.notice WHERE crash = ?', array($id))->fetchAll();
        $stack = $app['db']->executeQuery('SELECT frame, rendered, url FROM frame WHERE crash = ? AND thread = ? ORDER BY frame', array($id, $crash['thread']))->fetchAll();
        $modules = $app['db']->executeQuery('SELECT name, identifier, processed, present, HEX(base) AS base FROM module WHERE crash = ? ORDER BY name', array($id))->fetchAll();
        $stats = $app['db']->executeQuery('SELECT COUNT(DISTINCT crash.owner_id) AS owners, COUNT(DISTINCT crash.ip) AS ips, COUNT(*) AS crashes FROM crash, (SELECT owner_id, stackhash FROM crash WHERE id = ?) AS this WHERE this.stackhash = crash.stackhash', [$id])->fetch();

        $outdated = false;
        if ($app['config']['accelerator']) {
            $outdated = !isset($crash['metadata']['ExtensionVersion']) || version_compare($crash['metadata']['ExtensionVersion'], $app['config']['accelerator'], '<');
        }

        $has_error_string = false;
        if (isset($stack[0]['rendered'])) {
            $has_error_string = preg_match('/^engine(_srv)?\\.so!Sys_Error(_Internal)?\\(/', $stack[0]['rendered']) === 1;
        }

        $show_sourcepawn_message = false;
        if (isset($crash['metadata']['SourceModVersion']) && version_compare($crash['metadata']['SourceModVersion'], '1.10.0.6431', '<')) {
            foreach ($stack as $frame) {
                if (preg_match('/^sourcepawn\\.jit\\.[^!]+!sp::[^:]+::Invoke/', $frame['rendered']) === 1) {
                    $show_sourcepawn_message = true;
                    break;
                }
            }
        }

        return $app['twig']->render('details.html.twig', array(
            'crash' => $crash,
            'can_manage' => $can_manage,
            'notices' => $notices,
            'stack' => $stack,
            'modules' => $modules,
            'stats' => $stats,
            'outdated' => $outdated,
            'has_error_string' => $has_error_string,
            'show_sourcepawn_message' => $show_sourcepawn_message,
        ));
    }

    public function download(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            $app->abort(404);
        }

        return $app->sendFile($path)->setContentDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'crash_' . $id . '.dmp');
    }

    public function view(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        return $app['twig']->render('view.html.twig', array('id' => $id));
    }

    public function logs(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.txt';

        $logs = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $logs = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $logs = \Filesystem::readFile($path);
        }

        return $app['twig']->render('logs.html.twig', array('id' => $id, 'logs' => $logs));
    }

    public function metadata(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.meta.txt';

        $logs = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $logs = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $logs = \Filesystem::readFile($path);
        }

        return $app['twig']->render('logs.html.twig', array('id' => $id, 'logs' => $logs));
    }

    public function console(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.meta.txt';

        $metadata = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $metadata = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $metadata = \Filesystem::readFile($path);
        }

        $console = [];
        if ($metadata !== null) {
            $ret = preg_match('/(?<=-------- CONSOLE HISTORY BEGIN --------)[^\\x00]+(?=-------- CONSOLE HISTORY END --------)/i', $metadata, $matches);
            if ($ret === 1) {
                $console = $matches[0]; // Get the console output.
                $console = trim($console); // Remove the extra newlines from the markers.
                $console = str_replace("\r\n", PHP_EOL, $console); // Normalize line endings.

                // Split the console output into individual prints.
                preg_match_all('/(\\d+)\\((\\d+\\.?\\d*)\\):  ([^\\x00]*?)(?=(?:\\d+\\(\\d+\\.\\d+\\):  )|$)/', $console, $console, PREG_SET_ORDER);

                $console = array_reverse($console); // Flip them back into chronological order.
            }
        }

        return $app['twig']->render('console.html.twig', array('id' => $id, 'console' => $console));
    }

    public function error(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            return $app->json(array('string' => 'Minidump file is unavailable.'));
        }

        try {
            $minidump = \Filesystem::readFile($path);

            $output = array();

            $output['header'] = $header = unpack('A4magic/Lversion/Lstream_count/Lstream_offset', $minidump);
            if ($header === false || !isset($header['stream_offset'])) {
                throw new \RuntimeException('Failed to parse minidump header.');
            }

            $stream_offset = $header['stream_offset'];
            $stream = false;
            do {
                $output['stream'] = $stream = unpack('Ltype/Lsize/Loffset', substr($minidump, $stream_offset, 16));
                $stream_offset += 16;
            } while ($stream !== false && isset($stream['type']) && $stream['type'] !== 3);

            if ($stream === false || !isset($stream['offset'])) {
                throw new \RuntimeException('Missing MD_THREAD_LIST_STREAM.');
            }

            $threadId = $app['db']->executeQuery('SELECT thread FROM crash WHERE id = ? AND processed = 1 LIMIT 1', array($id))->fetchColumn(0);
            if ($threadId === false || $threadId === null) {
                throw new \RuntimeException('No processed thread information is available.');
            }

            $output['thread'] = $thread = unpack('Lthread_id/Lsuspend_count/Lpriority_class/Lpriority/L2teb/L2stack_start/Lstack_size/Lstack_offset/Lcontext_size/Lcontext_offset', substr($minidump, $stream['offset'] + 4 + (((int) $threadId) * 48), 48));
            if ($thread === false || !isset($thread['context_offset'], $thread['stack_start1'], $thread['stack_offset'])) {
                throw new \RuntimeException('Failed to parse minidump thread information.');
            }

            $output['context_flags'] = $context_flags = unpack('Lflags', substr($minidump, $thread['context_offset'], 4));
            if ($context_flags === false || !isset($context_flags['flags']) || ($context_flags['flags'] & 0x10000) === 0) {
                throw new \RuntimeException('Bad context flags.');
            }

            $getRegisterOffset = static function (string $minidump, int|string $stackStart, int $registerOffset): int {
                $contextRegister = unpack('Lregister', substr($minidump, $registerOffset, 4));
                if ($contextRegister === false || !isset($contextRegister['register'])) {
                    throw new \RuntimeException('Failed to read minidump register state.');
                }

                return (int) bcsub(sprintf('%u', $contextRegister['register']), sprintf('%u', $stackStart));
            };

            $output['register_esp'] = $register_esp = $getRegisterOffset($minidump, $thread['stack_start1'], $thread['context_offset'] + 196);
            $output['register_ebp'] = $register_ebp = $getRegisterOffset($minidump, $thread['stack_start1'], $thread['context_offset'] + 180);

            $error_offset = 0;
            for ($i = 0; $i < 6; $i++) {
                $output['register_offset_'.$i] = $register_offset = $getRegisterOffset($minidump, $thread['stack_start1'], $thread['context_offset'] + 156 + ($i * 4));
                if ($register_offset >= $register_esp && $register_offset <= $register_ebp) {
                    $output['error_offset'] = $error_offset = $register_offset;
                    break;
                }
            }

            if ($error_offset === 0) {
                return $app->json(array('string' => 'Failed to extract error message.'));
            }

            $output['string_start'] = $string_start = $thread['stack_offset'] + $error_offset;
            $string_length = 0;

            while (isset($minidump[$string_start + $string_length]) && ord($minidump[$string_start + $string_length]) !== 0 && $string_length < 256) {
                $string_length++;
            }

            $output['string_length'] = $string_length;

            $output['error_string'] = $error_string = substr($minidump, $string_start, $string_length);

            // Remove non-ASCII chars, this needs a cleanup, but just fix the errors while encoding UTF-8 for now.
            $error_string = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '?', $error_string);

            return $app->json(array('string' => $error_string));
        } catch (\Throwable $exception) {
            return $app->json(array('string' => $exception->getMessage()));
        }
    }

    public function carburetor(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        return $app['twig']->render('carburetor.html.twig', array(
            'id' => $id,
            'scan' => $app['request']->get('scan', null),
            'symbols' => $app['request']->get('symbols', null),
        ));
    }

    public function carburetor_data(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $options = [];
        if ($app['request']->get('scan', null) === 'no') {
            $options[] = '--no-scan';
        }

        $symbol_stores = [];
        if ($app['request']->get('symbols', null) !== 'no') {
            $symbol_stores = array_map(function ($p) use ($app) {
                return $app['root'].'/symbols/'.$p;
            }, $app['config']['symbol-stores']);

            array_unshift($symbol_stores, $app['root'].'/cache/symbols');
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            return new \Symfony\Component\HttpFoundation\Response(json_encode([
                'error' => 'Minidump file is unavailable.',
            ]), 200, array(
                'Content-Type' => 'application/json',
            ));
        }

        set_time_limit(120);

        try {
            list($stdout, $stderr) = execx($app['root'].'/bin/carburetor %Ls %s %Ls', $options, $path, $symbol_stores);
        } catch (\Throwable $exception) {
            $stdout = json_encode([
                'error' => trim($exception->getMessage()) ?: 'Carburetor failed to analyze the minidump.',
            ]);
        }

        return new \Symfony\Component\HttpFoundation\Response($stdout, 200, array(
            'Content-Type' => 'application/json',
        ));
    }

    public function reprocess(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        if (!$app['user']['admin']) {
            $app->abort(403);
        }

        $app['db']->transactional(function($db) use ($id) {
            $db->executeUpdate('DELETE FROM frame WHERE crash = ?', array($id));
            $db->executeUpdate('DELETE FROM module WHERE crash = ?', array($id));
            $db->executeUpdate('DELETE FROM crashnotice WHERE crash = ?', array($id));

            $db->executeUpdate('UPDATE crash SET thread = NULL, processed = FALSE, failed = FALSE, stackhash = NULL WHERE id = ?', array($id));
        });

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null), 'dashboard');

        return $app->redirect($return);
    }

    public function delete(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $app['db']->executeUpdate('DELETE FROM crash WHERE id = ?', array($id));

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null), 'dashboard');

        return $app->redirect($return);
    }

    public function dashboard(Application $app, $offset)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $shared = $app['user']['owners'];
        $userid = $app['request']->get('user', null);
        if ($userid !== null && ctype_digit((string) $userid)) {
            $userid = (int) $userid;
        } else {
            $userid = null;
        }

        $allowed = null;
        if (!$app['user']['admin']) {
            $allowed = $app['user']['owner_ids'];

            if ($userid !== null && !in_array($userid, $allowed, true)) {
                $app->abort(403);
            }
        }

        $where = '';
        $params = [];
        $types = [];

        if ($offset !== null || $userid !== null || $allowed !== null) {
            $where .= 'WHERE ';


            if ($userid !== null || $allowed !== null) {
                if ($userid !== null) {
                    $where .= 'crash.owner_id = ?';
                    $params[] = $userid;
                    $types[] = \PDO::PARAM_INT;
                } else if ($allowed !== null) {
                    $where .= 'crash.owner_id IN (?)';
                    $params[] = $allowed;
                    $types[] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
                }

                if ($offset !== null) {
                    $where .= ' AND ';
                }
            }

            if ($offset !== null) {
                $where .= 'timestamp < FROM_UNIXTIME(?)';
                $params[] = $offset;
                $types[] = \PDO::PARAM_INT;
            }
        }

        $crashes = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) as timestamp, crash.owner_id AS owner, crash.cmdline, crash.processed, crash.failed, server_owner.name, NULL AS avatar, frame.module, frame.rendered, frame2.module as module2, frame2.rendered AS rendered2, (SELECT CONCAT(COUNT(*), \'-\', MIN(notice.severity)) FROM crashnotice JOIN notice ON crashnotice.notice = notice.id WHERE crashnotice.crash = crash.id) AS notice FROM crash LEFT JOIN server_owner ON crash.owner_id = server_owner.id LEFT JOIN frame ON crash.id = frame.crash AND crash.thread = frame.thread AND frame.frame = 0 LEFT JOIN frame AS frame2 ON crash.id = frame2.crash AND crash.thread = frame2.thread AND frame2.frame = 1 ' . $where . ' ORDER BY crash.timestamp DESC LIMIT 20', $params, $types)->fetchAll();

        foreach ($crashes as &$crash) {
            foreach ($shared as $owner) {
                if ($owner['id'] === $crash['owner']) {
                    $crash['avatar'] = $owner['avatar'];
                    break;
                }
            }
        }
        unset($crash);

        return $app['twig']->render('dashboard.html.twig', array(
            'userid' => $userid,
            'shared' => $shared,
            'offset' => $offset,
            'crashes' => $crashes,
        ));
    }
}

