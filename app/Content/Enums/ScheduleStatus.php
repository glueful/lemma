<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum ScheduleStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
