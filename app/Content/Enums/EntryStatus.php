<?php

declare(strict_types=1);

namespace App\Content\Enums;

enum EntryStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Deleted = 'deleted';
}
