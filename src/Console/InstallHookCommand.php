<?php

declare(strict_types=1);

namespace Js199069\Agentlock\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'install-hook', description: 'Install a git pre-commit hook and show the Claude Code hook setting')]
final class InstallHookCommand extends Command
{
    private const HOOK = <<<'SH'
    #!/bin/sh
    # agentlock: block commits that weaken tests
    php vendor/bin/agentlock scan --staged
    status=$?
    if [ $status -ne 0 ]; then
      echo "agentlock: commit blocked. Review the findings above (git commit --no-verify overrides)."
    fi
    exit $status

    SH;

    protected function configure(): void
    {
        $this->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Repository root', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hooks = rtrim((string) $input->getOption('dir'), '/\\') . '/.git/hooks';
        if (! is_dir($hooks)) {
            $io->error("No .git/hooks directory at {$hooks}");

            return Command::FAILURE;
        }

        file_put_contents($hooks . '/pre-commit', self::HOOK);
        @chmod($hooks . '/pre-commit', 0755);
        $io->success('Installed .git/hooks/pre-commit');

        $io->section('Claude Code (.claude/settings.json): check the agent\'s work when it stops');
        $io->writeln(json_encode(['hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'php vendor/bin/agentlock scan --stderr --exit-code=2']]]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $io->note('Hooks run on the developer\'s machine and can be skipped. CI (scan --base=origin/main) is the gate you can rely on.');

        return Command::SUCCESS;
    }
}
