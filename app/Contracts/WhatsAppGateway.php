<?php

namespace App\Contracts;

interface WhatsAppGateway
{
    public function send(string $recipient, string $message): void;
}
