<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'topdata:readable-sql-exceptions:example',
    description: 'Example command for ReadableSqlExceptionsSW6 plugin'
)]
class ExampleCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>ReadableSqlExceptionsSW6 plugin example command executed successfully!</info>');
        $output->writeln('This is a minimal example command to get you started.');
        
        return Command::SUCCESS;
    }
}