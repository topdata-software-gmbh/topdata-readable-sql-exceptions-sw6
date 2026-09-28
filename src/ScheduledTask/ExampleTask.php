<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ExampleTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata_readable_sql_exceptions_s_w6.example';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24 hours in seconds
    }
}