<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Console;

use Js199069\Agentlock\Lock\Locker;
use Js199069\Agentlock\Report;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'verify', description: 'Check that locked files and tests are unchanged')]
final class VerifyCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Repository root', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locker = new Locker((string) $input->getOption('dir'));
        if (! $locker->exists()) {
            $output->writeln("agentlock: no lock file. Use 'agentlock lock <targets>' first.");

            return Command::SUCCESS;
        }

        $findings = $locker->verify();
        $output->write($findings === [] ? "agentlock: all locks hold.\n" : Report::text($findings, 0));

        return $findings === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
