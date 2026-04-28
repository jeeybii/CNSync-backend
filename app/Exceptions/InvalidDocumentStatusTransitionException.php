<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use RuntimeException;

class InvalidDocumentStatusTransitionException extends RuntimeException
{
    public static function fromStatuses(DocumentStatus $current, DocumentStatus $next): self
    {
        return new self(sprintf(
            'Invalid document status transition from [%s] to [%s].',
            $current->value,
            $next->value,
        ));
    }
}
