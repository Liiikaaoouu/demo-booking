<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Правила бронирования и их проверка.
 *
 * Время внутри сервиса — всегда UTC, потому что абсолютный момент не зависит
 * от часового пояса. В часовой пояс офиса оно переводится только там, где
 * важно «местное» время: рабочие часы и шаг сетки.
 */
class BookingScheduler
{
    public const int WORKING_DAY_START_HOUR = 9;

    public const int WORKING_DAY_END_HOUR = 20;

    public const int SLOT_STEP_MINUTES = 15;

    public const int MIN_DURATION_MINUTES = 15;

    public const int MAX_DURATION_MINUTES = 240;

    /**
     * @throws ValidationException
     */
    public function create(User $user, Room $room, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $title): Booking
    {
        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();

        $this->ensureDuration($startsAt, $endsAt);
        $this->ensureSlotGrid($room, $startsAt, $endsAt);
        $this->ensureWorkingHours($room, $startsAt, $endsAt);
        $this->ensureRoomIsFree($room, $startsAt, $endsAt);

        try {
            return $user->bookings()->create([
                'room_id' => $room->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'title' => $title,
            ]);
        } catch (QueryException $exception) {
            // Гонка двух запросов: проверка выше прошла у обоих, но записать
            // второй пересекающийся интервал не дал exclusion constraint.
            if ($this->isExclusionViolation($exception)) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Это время только что заняли. Выберите другой слот.',
                ]);
            }

            throw $exception;
        }
    }

    private function ensureDuration(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $minutes = intdiv($endsAt->getTimestamp() - $startsAt->getTimestamp(), 60);

        if ($minutes < self::MIN_DURATION_MINUTES || $minutes > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'ends_at' => 'Встреча длится от 15 минут до 4 часов.',
            ]);
        }
    }

    private function ensureSlotGrid(Room $room, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $timezone = $room->office->timezone;

        if (! $this->isOnGrid($startsAt, $timezone)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Начало встречи кратно 15 минутам.',
            ]);
        }

        if (! $this->isOnGrid($endsAt, $timezone)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Окончание встречи кратно 15 минутам.',
            ]);
        }
    }

    private function isOnGrid(CarbonImmutable $moment, string $timezone): bool
    {
        $local = $moment->setTimezone($timezone);

        return $local->minute % self::SLOT_STEP_MINUTES === 0 && $local->second === 0;
    }

    private function ensureWorkingHours(Room $room, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $timezone = $room->office->timezone;
        $localStartsAt = $startsAt->setTimezone($timezone);
        $localEndsAt = $endsAt->setTimezone($timezone);

        if (! $localStartsAt->isSameDay($localEndsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Встреча должна закончиться в тот же день.',
            ]);
        }

        $startOfDay = self::WORKING_DAY_START_HOUR * 60;
        $endOfDay = self::WORKING_DAY_END_HOUR * 60;

        if ($localStartsAt->hour * 60 + $localStartsAt->minute < $startOfDay) {
            throw ValidationException::withMessages([
                'starts_at' => $this->workingHoursMessage($room, 'начинается раньше'),
            ]);
        }

        if ($localEndsAt->hour * 60 + $localEndsAt->minute > $endOfDay) {
            throw ValidationException::withMessages([
                'ends_at' => $this->workingHoursMessage($room, 'заканчивается позже'),
            ]);
        }
    }

    private function workingHoursMessage(Room $room, string $problem): string
    {
        return sprintf(
            'Встреча %s рабочего времени: переговорная доступна с %02d:00 до %02d:00 по времени офиса «%s» (%s).',
            $problem,
            self::WORKING_DAY_START_HOUR,
            self::WORKING_DAY_END_HOUR,
            $room->office->name,
            $room->office->timezone,
        );
    }

    private function ensureRoomIsFree(Room $room, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $isBusy = Booking::query()
            ->where('room_id', $room->id)
            ->overlapping($startsAt, $endsAt)
            ->exists();

        if ($isBusy) {
            throw ValidationException::withMessages([
                'starts_at' => 'Это время уже занято в выбранной переговорной.',
            ]);
        }
    }

    private function isExclusionViolation(QueryException $exception): bool
    {
        // SQLSTATE 23P01 — exclusion_violation, нарушено EXCLUDE-ограничение.
        return $exception->getCode() === '23P01';
    }
}
