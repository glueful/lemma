<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum ScheduleAction: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
}
