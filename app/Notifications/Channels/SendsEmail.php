<?php

namespace App\Notifications\Channels;

interface SendsEmail
{
    public function getRecipients(): array;
}
// resync-marker 2026-04-08
