<?php

namespace Throttle\Command;

use App\Legacy\LegacyBridgeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class SymbolsUpdateCommand extends Command
{
    private LegacyBridgeFactory $legacyBridgeFactory;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory)
    {
        parent::__construct();
        $this->legacyBridgeFactory = $legacyBridgeFactory;
    }

    protected function configure(): void
    {
        $this->setName('symbols:update')
            ->setDescription('Update module information in database.')
            ->addOption(
                'clean',
                'c',
                InputOption::VALUE_NONE,
                'Rebuild all module information rather than just missing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->legacyBridgeFactory->createConsole();

        $symbols = \Filesystem::listDirectory($app['root'] . '/symbols');
        $query = $app['db']->executeQuery('SELECT DISTINCT name, identifier FROM module WHERE identifier != \'000000000000000000000000000000000\'' . ($input->getOption('clean') ? '' : ' AND present = 0'));
        $count = $query->rowCount();

        $progress = new ProgressBar($output, $count);
        $progress->start();

        while (($module = $query->fetch()) !== false) {
            $found = false;

            $symname = $module['name'];
            if (stripos($symname, '.pdb') == strlen($symname) - 4) {
                $symname = substr($symname, 0, -4);
            }

            foreach ($symbols as $path) {
                if (file_exists($app['root'] . '/symbols/' . $path . '/' . $module['name'] . '/' . $module['identifier'] . '/' . $symname . '.sym.gz')) {
                    $found = true;
                    break;
                }
            }

            $progress->advance();

            if (!$found && !$input->getOption('clean')) {
                continue;
            }

            $app['db']->executeUpdate('UPDATE module SET present = ? WHERE name = ? AND identifier = ?', array((int) $found, $module['name'], $module['identifier']));
        }

        $progress->finish();

        $output->writeln('Waiting for processing lock...');

        $lock = \PhutilFileLock::newForPath($app['root'] . '/cache/process.lck');
        $lock->lock(300);

        $app['redis']->del('throttle:cache:symbol');

        $output->writeln('Flushed symbol cache');

        $lock->unlock();

        return Command::SUCCESS;
    }
}

