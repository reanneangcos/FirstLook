<?php

namespace App\Services\Screening;

use RuntimeException;

class InvalidModelOutput extends RuntimeException
{
    public function __construct(public readonly string $failureCode = 'invalid_output', public readonly ?string $field = null)
    {
        parent::__construct('The model response did not satisfy the screening output contract.');
    }
}
