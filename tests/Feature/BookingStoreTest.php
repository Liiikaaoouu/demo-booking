<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $moscowRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->moscowRoom = Room::factory()->create();

        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'room_id' => $this->moscowRoom->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
            ...$overrides,
        ];
    }

    public function test_creates_booking(): void
    {
        $this->postJson('/api/bookings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('title', 'Планёрка');

        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame($this->moscowRoom->id, Booking::firstOrFail()->room_id);
    }

    public function test_stores_time_as_absolute_moment_not_client_wall_clock(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        $booking = Booking::firstOrFail();

        // 10:00 по Москве — это 07:00 UTC, в каком бы смещении клиент ни прислал время.
        $this->assertSame('2026-12-01T07:00:00+00:00', $booking->starts_at->toIso8601String());
        $this->assertSame('2026-12-01T08:00:00+00:00', $booking->ends_at->toIso8601String());
    }

    public function test_author_comes_from_token_not_from_request_body(): void
    {
        $anotherUser = User::factory()->create();

        $this->postJson('/api/bookings', $this->payload(['user_id' => $anotherUser->id]))
            ->assertCreated();

        $this->assertSame($this->user->id, Booking::firstOrFail()->user_id);
    }

    public function test_allows_adjacent_bookings(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T11:00:00+03:00',
            'ends_at' => '2026-12-01T12:00:00+03:00',
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_rejects_overlapping_booking(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:30:00+03:00',
            'ends_at' => '2026-12-01T11:30:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_rejects_same_slot_in_another_timezone(): void
    {
        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        // 07:00Z — тот же момент, что 10:00+03:00, только записанный иначе.
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T07:00:00+00:00',
            'ends_at' => '2026-12-01T08:00:00+00:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_accepts_shortest_booking(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T10:15:00+03:00',
        ]))->assertCreated();
    }

    public function test_accepts_longest_booking(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T14:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_rejects_booking_shorter_than_15_minutes(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T10:14:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_booking_longer_than_four_hours(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T14:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_start_off_the_15_minute_grid(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:07:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_end_off_the_15_minute_grid(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T10:00:00+03:00',
            'ends_at' => '2026-12-01T10:50:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_accepts_booking_filling_whole_working_day(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T09:00:00+03:00',
            'ends_at' => '2026-12-01T13:00:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T16:00:00+03:00',
            'ends_at' => '2026-12-01T20:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_rejects_booking_starting_before_opening(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T08:45:00+03:00',
            'ends_at' => '2026-12-01T09:45:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_booking_ending_after_closing(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T19:15:00+03:00',
            'ends_at' => '2026-12-01T20:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_working_hours_are_checked_in_office_timezone(): void
    {
        $novosibirskRoom = Room::factory()
            ->for(Office::factory()->novosibirsk())
            ->create();

        // 02:00 UTC — это 09:00 в Новосибирске, рабочий день только начался.
        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $novosibirskRoom->id,
            'starts_at' => '2026-12-01T02:00:00+00:00',
            'ends_at' => '2026-12-01T03:00:00+00:00',
        ]))->assertCreated();

        // 23:30 UTC — это 06:30 следующего дня в Новосибирске, до открытия.
        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $novosibirskRoom->id,
            'starts_at' => '2026-12-01T23:30:00+00:00',
            'ends_at' => '2026-12-02T00:30:00+00:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_same_moment_is_valid_for_office_whose_day_already_started(): void
    {
        $novosibirskRoom = Room::factory()
            ->for(Office::factory()->novosibirsk())
            ->create();

        // 06:00 UTC — 13:00 в Новосибирске, но 09:00 по Москве: для новосибирской
        // комнаты это рабочий день, хотя «час» в присланном смещении равен 9.
        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $novosibirskRoom->id,
            'starts_at' => '2026-12-01T06:00:00+00:00',
            'ends_at' => '2026-12-01T07:00:00+00:00',
        ]))->assertCreated();
    }

    public function test_accepts_time_in_any_iso_8601_offset(): void
    {
        $novosibirskRoom = Room::factory()
            ->for(Office::factory()->novosibirsk())
            ->create();

        // 09:00 по Новосибирску, присланное в смещении +07:00.
        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $novosibirskRoom->id,
            'starts_at' => '2026-12-01T09:00:00+07:00',
            'ends_at' => '2026-12-01T10:00:00+07:00',
        ]))->assertCreated();
    }

    public function test_does_not_add_restrictions_on_dates_other_than_working_hours(): void
    {
        // В задании нет ограничений на дату, кроме рабочих часов: прошлое разрешено.
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2020-01-15T10:00:00+03:00',
            'ends_at' => '2020-01-15T11:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_validates_required_fields(): void
    {
        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
    }

    public function test_rejects_unknown_room(): void
    {
        $this->postJson('/api/bookings', $this->payload(['room_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_rejects_room_id_that_is_not_a_number(): void
    {
        $this->postJson('/api/bookings', $this->payload(['room_id' => '1 OR 1=1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_rejects_end_before_start(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T11:00:00+03:00',
            'ends_at' => '2026-12-01T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_end_equal_to_start(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => '2026-12-01T11:00:00+03:00',
            'ends_at' => '2026-12-01T11:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_invalid_date_format(): void
    {
        $this->postJson('/api/bookings', $this->payload([
            'starts_at' => 'завтра в десять',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_too_long_title(): void
    {
        $this->postJson('/api/bookings', $this->payload(['title' => str_repeat('a', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_bookings_of_different_rooms_do_not_conflict(): void
    {
        $anotherRoom = Room::factory()->create();

        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        $this->postJson('/api/bookings', $this->payload(['room_id' => $anotherRoom->id]))
            ->assertCreated();
    }

    /**
     * Гонка двух запросов: проверка «свободно» прошла, но слот заняли до вставки.
     * Воспроизводим детерминированно — вставляем конфликтующую бронь в событии
     * creating, то есть ровно между проверкой и INSERT.
     */
    public function test_reports_validation_error_when_slot_is_taken_between_check_and_insert(): void
    {
        $injected = false;

        Booking::creating(function () use (&$injected): void {
            if ($injected) {
                return;
            }

            $injected = true;

            Booking::withoutEvents(fn () => $this->user->bookings()->create([
                'room_id' => $this->moscowRoom->id,
                'title' => 'Перехваченная бронь',
                // 10:00–11:00 по Москве = 07:00–08:00 UTC.
                'starts_at' => '2026-12-01 07:00:00+00',
                'ends_at' => '2026-12-01 08:00:00+00',
            ]));
        });

        $this->postJson('/api/bookings', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertTrue($injected);
    }
}
