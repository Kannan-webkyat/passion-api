<?php

namespace App\Exceptions;

use RuntimeException;

class LiquorTaxValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $itemName = null,
    ) {
        parent::__construct($message);
    }
}
