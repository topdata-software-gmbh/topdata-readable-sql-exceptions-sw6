<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\ScheduledTask;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ExampleTask::class)]
class ExampleTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logDir
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public function run(): void
    {
        $logFile = $this->logDir . '/topdata-readable-sql-exceptions-sw6.log';
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $message = sprintf("[%s] [INFO] [ReadableSqlExceptionsSW6] ExampleTask ping executed successfully.\n", $timestamp);

        @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
}