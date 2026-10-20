<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Facades\Assets;
use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Enums\ReservationStatusEnum;
use Botble\Restaurant\Http\Requests\ReservationRequest;
use Botble\Restaurant\Models\Reservation;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class BookingCalendarController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.menu_name'))
            ->add(trans('plugins/restaurant::restaurant.booking_calendar'), route('restaurant.booking-calendar.index'));
    }

    public function index()
    {
        $this->pageTitle(trans('plugins/restaurant::restaurant.booking_calendar'));

        Assets::addScriptsDirectly([
            'vendor/core/plugins/restaurant/libraries/full-calendar-6.1.8/main.min.js',
            'vendor/core/plugins/restaurant/js/booking-calendar.js',
        ]);

        Assets::addStylesDirectly([
            'vendor/core/plugins/restaurant/libraries/full-calendar-6.1.8/main.min.css',
            'vendor/core/plugins/restaurant/css/booking-calendar.css',
        ]);

        Assets::usingVueJS();

        return view('plugins/restaurant::booking-calendar');
    }

    public function getBookingRecords(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        $query = Reservation::query();

        if ($start && $end) {
            $start = preg_replace('/\s+/', '+', $start);
            $end = preg_replace('/\s+/', '+', $end);

            try {
                $startDate = Carbon::parse($start)->startOfDay();
                $endDate = Carbon::parse($end)->endOfDay();

                $query->whereBetween('reservation_date', [$startDate, $endDate]);
            } catch (Exception) {
                $start = explode('T', $start)[0] ?? $start;
                $end = explode('T', $end)[0] ?? $end;

                $query->whereBetween('reservation_date', [
                    Carbon::parse($start)->startOfDay(),
                    Carbon::parse($end)->endOfDay(),
                ]);
            }
        }

        $reservations = $query->get();

        $events = $reservations->map(function ($reservation) {
            if ($reservation->reservation_time instanceof Carbon) {
                $time = $reservation->reservation_time->format('H:i:s');
            } elseif (str_contains($reservation->reservation_time, ' ')) {
                $time = Carbon::parse($reservation->reservation_time)->format('H:i:s');
            } else {
                $time = $reservation->reservation_time;
            }

            $startDateTime = Carbon::parse($reservation->reservation_date->format('Y-m-d') . ' ' . $time);
            $endDateTime = $startDateTime->copy()->addHours(2);

            return [
                'id' => $reservation->id,
                'title' => $reservation->name . ' (' . $reservation->number_of_guests . ' guests)',
                'start' => $startDateTime->toIso8601String(),
                'end' => $endDateTime->toIso8601String(),
                'backgroundColor' => $this->getStatusColor($reservation->status),
                'borderColor' => $this->getStatusColor($reservation->status),
                'extendedProps' => [
                    'name' => $reservation->name,
                    'email' => $reservation->email,
                    'phone' => $reservation->phone,
                    'guests' => $reservation->number_of_guests,
                    'status' => $reservation->status->label(),
                    'special_requests' => $reservation->special_requests,
                ],
            ];
        });

        return response()->json($events);
    }

    protected function getStatusColor($status): string
    {
        $statusValue = $status instanceof ReservationStatusEnum
            ? $status->getValue()
            : (string) $status;

        return match ($statusValue) {
            'pending' => '#ffc107',
            'confirmed' => '#28a745',
            'cancelled' => '#dc3545',
            'completed' => '#6c757d',
            default => '#007bff',
        };
    }

    public function getBookingDetail($id)
    {
        $reservation = Reservation::query()->findOrFail($id);

        $html = view('plugins/restaurant::partials.booking-detail', compact('reservation'))->render();

        return response()->json([
            'html' => $html,
            'editUrl' => route('restaurant.reservations.edit', $reservation->id),
        ]);
    }

    public function storeReservation(ReservationRequest $request, BaseHttpResponse $response)
    {
        try {
            $reservation = Reservation::query()->create($request->validated());

            event(new CreatedContentEvent(RESERVATION_MODULE_SCREEN_NAME, $request, $reservation));

            // Send confirmation email to customer
            if ($reservation->email) {
                EmailHandler::setModule(RESTAURANT_MODULE_SCREEN_NAME)
                    ->setVariableValues([
                        'reservation_name' => $reservation->name,
                        'reservation_email' => $reservation->email,
                        'reservation_date' => $reservation->reservation_date->format('M d, Y'),
                        'reservation_time' => $reservation->reservation_time,
                        'number_of_guests' => $reservation->number_of_guests,
                        'special_requests' => $reservation->special_requests ?: '',
                        'restaurant_phone' => theme_option('booking_phone'),
                        'restaurant_email' => theme_option('email'),
                        'site_url' => BaseHelper::getHomepageUrl(),
                    ])
                    ->sendUsingTemplate('reservation_confirmation', $reservation->email);
            }

            return $response
                ->setMessage(trans('plugins/restaurant::reservation.reservation_created_successfully'));
        } catch (Exception $exception) {
            return $response
                ->setError()
                ->setMessage($exception->getMessage());
        }
    }
}
