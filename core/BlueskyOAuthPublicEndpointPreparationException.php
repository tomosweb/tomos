<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class BlueskyOAuthPublicEndpointPreparationException extends RuntimeException
{
    private string $diagnosticCode;

    public function __construct(string $diagnosticCode)
    {
        parent::__construct('Bluesky public endpoint preparation failed.');
        $this->diagnosticCode = $diagnosticCode;
    }

    public function diagnosticCode(): string
    {
        return $this->diagnosticCode;
    }
}
