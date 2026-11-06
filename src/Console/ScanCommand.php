<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Console;

use Js199069\Agentlock\Change\Git;
use Js199069\Agentlock\Lock\Locker;
use Js199069\Agentlock\Mutation\MutationCheck;
use Js199069\Agentlock\Report;
use Js199069\Agentlock\Scanner;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'scan', description: 'Inspect changes for signs that tests were weakened to make them pass')]
final class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Repository root', getcwd())
            ->addOption('staged', null, InputOption::VALUE_NONE, 'Inspect staged changes (pre-commit)')
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Inspect HEAD against the merge base with this ref (CI), e.g. origin/main')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'high, medium, low or none', 'high')
            ->addOption('exit-code', null, InputOption::VALUE_REQUIRED, 'Exit code when findings reach --fail-on (2 blocks Claude Code hooks)', '1')
            ->addOption('stderr', null, InputOption::VALUE_NONE, 'Write the report to stderr (for agent hooks)')
            ->addOption('mutation', null, InputOption::VALUE_NONE, 'Also compare MSI with the stored baseline (slow; needs Infection)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = rtrim((string) $input->getOption('dir'), '/\\');
        $out = $input->getOption('stderr') && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $mode = $input->getOption('staged') ? 'staged' : ($input->getOption('base') !== null ? 'base' : 'working');

        try {
            $changes = (new Git($dir))->changes($mode, $input->getOption('base'));
        } catch (RuntimeException $e) {
            $out->writeln('<error>agentlock: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $findings = (new Scanner())->scan($changes);

        $locker = new Locker($dir);
        if ($locker->exists()) {
            array_push($findings, ...$locker->verify());
        }

        if ($input->getOption('mutation')) {
            $out->writeln('agentlock: measuring MSI against the baseline...');
            array_push($findings, ...(new MutationCheck($dir))->check(
                fn (string $file, float $before, float $now) => $out->writeln(sprintf('  %s: %.2f%% → %.2f%%', $file, $before, $now)),
            ));
        }

        $out->write($input->getOption('format') === 'json' ? Report::json($findings) : Report::text($findings, count($changes)));

        return Report::failed($findings, (string) $input->getOption('fail-on')) ? (int) $input->getOption('exit-code') : Command::SUCCESS;
    }
}
