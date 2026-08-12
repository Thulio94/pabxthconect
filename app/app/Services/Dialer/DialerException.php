<?php

namespace App\Services\Dialer;

use RuntimeException;

class DialerException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
