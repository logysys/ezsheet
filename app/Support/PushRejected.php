<?php
namespace App\Support;

/** Thrown inside the push transaction to roll it back and return a 4xx JSON body. */
class PushRejected extends \RuntimeException
{
    public function __construct(public int $status, public array $payload)
    {
        parent::__construct($payload['error'] ?? 'push_rejected');
    }
}
