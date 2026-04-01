<?php

namespace App\Message;

class BindDialogMessage
{
    public function __construct(
        public readonly string $email,
        public readonly string $dialogId,
        public readonly int $attempt = 1,
    ) {}
}
