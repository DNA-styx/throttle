<?php

namespace Throttle\Command;

use App\Legacy\LegacyBridgeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SymbolsDumpCommand extends Command
{
    private LegacyBridgeFactory $legacyBridgeFactory;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory)
    {
        parent::__construct();
        $this->legacyBridgeFactory = $legacyBridgeFactory;
    }

    protected function configure(): void
    {
        $this->setName('symbols:dump')
            ->setDescription('Dump symbol data from binary. This should only be used for binaries missing debugging information.')
            ->addArgument(
                'binary',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Binaries to process, seperate multiple values with a space'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->legacyBridgeFactory->createConsole();
        $nmBinary = \Filesystem::resolveBinary('nm') ?? ($app['root'] . '/bin/nm');

        $table = new Table($output);
        $table->setHeaders(array('Binary', 'Identifier'));

        $moduleFutures = array();
        $symbolFutures = array();
        $binaries = $input->getArgument('binary');
        foreach ($binaries as $binary) {
            $moduleFutures[$binary] = new \ExecFuture($app['root'] . '/bin/breakpad_moduleid %s', $binary);
            $symbolFutures[$binary] = new \ExecFuture('%s -nC %s', $nmBinary, $binary);
        }

        $identifiers = array();
        foreach (id(new \FutureIterator($moduleFutures))->limit(5) as $name => $future) {
            list($stdout, $stderr) = $future->resolvex();
            $identifier = rtrim($stdout);

            $identifiers[$name] = $identifier;
            $table->addRow(array(basename($name), $identifier));
        }

        foreach (id(new \FutureIterator($symbolFutures))->limit(5) as $name => $future) {
            $basename = basename($name);
            $identifier = $identifiers[$name];
            $architecture = $this->detectArchitecture($name);

            $path = $app['root'] . '/symbols/public/' . $basename . '/' . $identifier;
            $file = $path . '/' . $basename . '.sym';
            \Filesystem::createDirectory($path, 0777, true);

            \Filesystem::writeFile($file, 'MODULE Linux ' . $architecture . ' ' . $identifier . ' ' . $basename . PHP_EOL);
            foreach (new \LinesOfALargeExecFuture($future) as $line) {
                if (!preg_match('/^0+([0-9a-fA-F]+) +[tT] +([0-9a-zA-Z_.* ,():&]+)$/', $line, $matches)) {
                    continue;
                }

                \Filesystem::appendFile($file, 'PUBLIC ' . $matches[1] . ' 0 ' . $matches[2] . PHP_EOL);
            }
        }

        $table->render();

        return Command::SUCCESS;
    }

    private function detectArchitecture(string $binary): string
    {
        $handle = @fopen($binary, 'rb');
        if ($handle === false) {
            return 'x86';
        }

        try {
            $header = fread($handle, 20);
            if ($header === false || strlen($header) < 20 || substr($header, 0, 4) !== "\x7FELF") {
                return 'x86';
            }

            $machine = unpack('v', substr($header, 18, 2))[1] ?? 0;

            return match ($machine) {
                62 => 'x86_64',
                183 => 'arm64',
                default => 'x86',
            };
        } finally {
            fclose($handle);
        }
    }
}

