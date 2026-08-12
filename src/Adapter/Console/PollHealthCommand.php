<?php

declare(strict_types=1);

namespace App\Adapter\Console;

use App\Application\RecordHealthSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:poll-health', description: 'Poll registered project health and ready endpoints.')]
final class PollHealthCommand extends Command
{
    public function __construct(private readonly RecordHealthSnapshot $recordHealthSnapshot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Poll a single game id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $input->getOption('project');
        $snapshots = is_string($project) && $project !== ''
            ? $this->recordHealthSnapshot->forGameId($project)
            : $this->recordHealthSnapshot->forAll();

        foreach ($snapshots as $snapshot) {
            $output->writeln(sprintf(
                '%s %s %s http=%d %dms',
                $snapshot->gameId->value,
                $snapshot->endpoint->value,
                $snapshot->status->value,
                $snapshot->httpCode,
                $snapshot->latencyMs,
            ));
        }

        return Command::SUCCESS;
    }
}
