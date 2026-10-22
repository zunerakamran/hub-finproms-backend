<?php

namespace Tests\Unit;

use App\Support\WcEmbedTicket;
use Tests\TestCase;

class WcEmbedTicketTest extends TestCase
{
    public function test_issue_and_verify_round_trip(): void
    {
        $ticket = WcEmbedTicket::issue(7, 42);
        $this->assertTrue(WcEmbedTicket::verify($ticket, 7, 42));
        $this->assertFalse(WcEmbedTicket::verify($ticket, 7, 99));
        $this->assertFalse(WcEmbedTicket::verify($ticket, 8, 42));
        $this->assertFalse(WcEmbedTicket::verify('tampered.'.$ticket, 7, 42));
    }

    public function test_expired_ticket_is_rejected(): void
    {
        $exp = time() - 10;
        $payload = '7.42.'.$exp;
        $sig = hash_hmac('sha256', $payload, (string) config('app.key'));
        $ticket = $payload.'.'.$sig;

        $this->assertFalse(WcEmbedTicket::verify($ticket, 7, 42));
    }
}
