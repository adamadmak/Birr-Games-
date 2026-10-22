<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Telegram player account is requested before the subscriber has
 * shared a verified contact card. Phone sharing is mandatory so that an
 * existing member is never registered a second time.
 */
class TelegramPhoneRequiredException extends RuntimeException
{
    public function __construct(string $message = 'Share your phone number in the bot before your player account can be opened.')
    {
        parent::__construct($message);
    }
}
