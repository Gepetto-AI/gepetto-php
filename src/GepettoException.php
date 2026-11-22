<?php

declare(strict_types=1);

namespace Gepetto;

/**
 * Every error of the SDK. `errorCode` is stable, for your code (`unknown_room`, `test_limit_reached`...);
 * the message says what to do next, for a person.
 *
 *     try {
 *         $job = $gepetto->furnish([...]);
 *     } catch (GepettoException $e) {
 *         echo $e->errorCode;  // "unknown_style"
 *         echo $e->param;      // "styleId"
 *         echo $e->getMessage();
 *     }
 *
 * getCode() is the HTTP status (0 when the API was not reached).
 */
class GepettoException extends \RuntimeException
{
    public function __construct(
        /** The API's error code, such as `invalid_parameter`, or `timeout` and `connection_error` from the SDK. */
        public readonly string $errorCode,
        string $message,
        /** The HTTP status, null when the API was not reached. */
        public readonly ?int $status = null,
        /** The parameter the error is about, such as `roomId`. */
        public readonly ?string $param = null,
        /** The job concerned, when a render was started: follow it with getJob(). */
        public readonly ?string $jobId = null,
        /** The failed or unfinished job, when there is one. */
        public readonly ?Job $job = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
