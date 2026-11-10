<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Проверка последней линии обороны — exclusion constraint в PostgreSQL.
 * Два параллельных запроса могут оба пройти проверку в приложении, поэтому
 * пересечение запрещает сама база.
 */
class BookingOverlapConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_overlapping_interval(): void
    {
        $room = Room::factory()->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-12-01 10:00:00+00',
            'ends_at' => '2026-12-01 11:00:00+00',
        ]);

        $this->expectException(QueryException::class);

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-12-01 10:30:00+00',
            'ends_at' => '2026-12-01 11:30:00+00',
        ]);
    }

    public function test_database_allows_adjacent_interval(): void
    {
        $room = Room::factory()->create();

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-12-01 10:00:00+00',
            'ends_at' => '2026-12-01 11:00:00+00',
        ]);

        Booking::factory()->for($room)->create([
            'starts_at' => '2026-12-01 11:00:00+00',
            'ends_at' => '2026-12-01 12:00:00+00',
        ]);

        $this->assertDatabaseCount('bookings', 2);
    }
}
