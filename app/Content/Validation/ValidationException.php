<?php

declare(strict_types=1);

namespace App\Content\Validation;

final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('field validation failed');
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
