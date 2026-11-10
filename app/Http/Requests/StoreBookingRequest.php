<?php

namespace App\Http\Requests;

use App\Models\Room;
use App\Rules\Iso8601DateTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * Правила формата: тип полей и их наличие. Бизнес-правила (рабочие часы,
     * длительность, пересечения) проверяет BookingScheduler — им нужен офис
     * переговорной и его часовой пояс.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', new Iso8601DateTime],
            'ends_at' => ['required', 'string', 'after:starts_at', new Iso8601DateTime],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите переговорную.',
            'room_id.integer' => 'Идентификатор переговорной должен быть числом.',
            'room_id.exists' => 'Такой переговорной нет.',
            'title.required' => 'Укажите тему встречи.',
            'title.string' => 'Тема встречи должна быть строкой.',
            'title.max' => 'Тема встречи не длиннее 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.after' => 'Время окончания должно быть позже времени начала.',
        ];
    }

    public function room(): Room
    {
        return Room::with('office')->findOrFail($this->integer('room_id'));
    }

    /**
     * Время приходит в любом смещении ISO 8601, внутри живёт в UTC.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('starts_at'))->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('ends_at'))->utc();
    }
}
