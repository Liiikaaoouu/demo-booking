<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_cancels_own_booking(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_another_user_cannot_cancel_booking(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($stranger);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_cancelling_unknown_booking_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999')->assertNotFound();
    }

    public function test_cancelled_booking_disappears_from_own_list(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->getJson('/api/bookings')->assertOk()->assertJsonCount(0, 'data');
    }
}
