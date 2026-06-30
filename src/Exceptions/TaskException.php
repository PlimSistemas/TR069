<?php

namespace Plimsistemas\TR069\Exceptions;

use RuntimeException;

class TaskException extends RuntimeException
{
    public static function failed(string $taskName, string $reason = ''): static
    {
        $message = "GenieACS task '{$taskName}' failed.";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }
        return new static($message);
    }
}
