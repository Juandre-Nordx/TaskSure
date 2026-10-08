<?php

namespace App\Services;

use App\Contracts\WhatsAppGateway;

class UnconfiguredWhatsApp implements WhatsAppGateway
{
    public function send(string $recipient, string $message): void
    {
        throw new \RuntimeException('WhatsApp is unconfigured. Bind a provider adapter to WhatsAppGateway.');
    }
}
