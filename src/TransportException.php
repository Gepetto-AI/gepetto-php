<?php

declare(strict_types=1);

namespace Gepetto;

/** The API could not be reached, or did not answer in time. Thrown by a transport, turned into a GepettoException. */
final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false)
    {
        parent::__construct($message);
    }
}
