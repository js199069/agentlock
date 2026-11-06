<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Console;

use Js199069\Agentlock\Lock\Locker;
use Js199069\Agentlock\Scanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'lock', description: 'Lock files or single tests that an agent must not change (the contract)')]
final class LockCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('targets', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Files, directories, or path/to/FileTest.php::test_name')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Repository root', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locked = (new Locker((string) $input->getOption('dir')))->lock($input->getArgument('targets'));

        $io->listing($locked);
        $io->success(count($locked) . ' item(s) locked in ' . Scanner::LOCK . '. Commit it: from now on, any change to these is reported as high.');

        return Command::SUCCESS;
    }
}
