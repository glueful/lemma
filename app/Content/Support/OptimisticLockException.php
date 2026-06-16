<?php

declare(strict_types=1);

namespace App\Content\Support;

final class OptimisticLockException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('draft was modified by another writer');
    }
}
