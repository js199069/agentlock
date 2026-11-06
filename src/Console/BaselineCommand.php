<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Console;

use Js199069\Agentlock\Mutation\MutationCheck;
use Js199069\Agentlock\Scanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'baseline', description: 'Record the MSI of important source files on a trusted commit (for scan --mutation)')]
final class BaselineCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Source files, e.g. src/Billing/Invoice.php')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Repository root', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new MutationCheck((string) $input->getOption('dir')))->baseline(
            $input->getArgument('files'),
            fn (string $file, float $msi) => $output->writeln(sprintf('  %s: %.2f%%', $file, $msi)),
        );
        $output->writeln('agentlock: baseline saved in ' . Scanner::BASELINE . '. Commit it.');

        return Command::SUCCESS;
    }
}
