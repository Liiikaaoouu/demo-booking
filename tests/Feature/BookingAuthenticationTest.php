<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_create_booking_without_token(): void
    {
        $this->postJson('/api/bookings', [
            'room_id' => 1,
            'title' => 'Планёрка',
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ])->assertUnauthorized();
    }

    public function test_cannot_cancel_booking_without_token(): void
    {
        $booking = Booking::factory()->for(User::factory())->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();
    }
}
