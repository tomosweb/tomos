<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class ThemePackageException extends RuntimeException
{
    private string $stage;

    public function __construct(string $message, string $stage)
    {
        parent::__construct($message);
        $this->stage = $stage;
    }

    public function stage(): string
    {
        return $this->stage;
    }
}
