<?php

declare(strict_types=1);

namespace App\Oai;

use RuntimeException;

final class OaiPmhException extends RuntimeException
{
    public function __construct(
        private readonly string $oaiCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getOaiCode(): string
    {
        return $this->oaiCode;
    }
}
