<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request, BookingScheduler $scheduler): JsonResponse
    {
        $booking = $scheduler->create(
            $request->user(),
            $request->room(),
            $request->startsAt(),
            $request->endsAt(),
            trim((string) $request->string('title')),
        );

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, Booking $booking): JsonResponse|Response
    {
        if ($booking->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Отменить бронь может только её автор.'], 403);
        }

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
