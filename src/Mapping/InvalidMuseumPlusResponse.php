<?php

declare(strict_types=1);

namespace App\Mapping;

use RuntimeException;

final class InvalidMuseumPlusResponse extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $responseBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function museumplusId(): ?string
    {
        // Only recognise the export's actual opening structure, not an ID in an
        // HTML error page or escaped field. Never repair or import malformed XML.
        if (preg_match('~\A(?:\xEF\xBB\xBF)?\s*(?:<\?xml\b[^?]*\?>\s*)?<ObjectList(?:\s[^>]*)?>\s*<Object(?:\s[^>]*)?>\s*<ID>\s*([0-9]{1,64})\s*</ID>~', $this->responseBody, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
