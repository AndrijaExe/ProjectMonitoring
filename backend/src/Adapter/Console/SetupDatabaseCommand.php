<?php

declare(strict_types=1);

namespace App\Adapter\Console;

use App\Adapter\Persistence\Postgres\PostgresSchema;
use App\Adapter\Persistence\ProjectCatalogSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:db-setup', description: 'Create missing tables and seed the project catalog.')]
final class SetupDatabaseCommand extends Command
{
    public function __construct(
        private readonly PostgresSchema $schema,
        private readonly ProjectCatalogSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->schema->install();
        $output->writeln('Schema is up to date.');

        $this->seeder->seed();
        $output->writeln('Project catalog seeded.');

        return Command::SUCCESS;
    }
}
