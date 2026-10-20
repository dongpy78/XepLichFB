<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Restaurant\Facades\Currency;
use Botble\Restaurant\Http\Requests\PublicReservationRequest;
use Botble\Restaurant\Models\Currency as CurrencyModel;
use Botble\Restaurant\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PublicController extends BaseController
{
    public function postReservation(PublicReservationRequest $request, BaseHttpResponse $response)
    {
        $reservation = Reservation::query()->create($request->validated());

        $emailHandler = EmailHandler::setModule(RESTAURANT_MODULE_SCREEN_NAME)
            ->setVariableValues([
                'reservation_name' => $reservation->name,
                'reservation_email' => $reservation->email,
                'reservation_phone' => $reservation->phone ?: 'N/A',
                'reservation_date' => $reservation->reservation_date->format('M d, Y'),
                'reservation_time' => $reservation->reservation_time,
                'number_of_guests' => $reservation->number_of_guests,
                'special_requests' => $reservation->special_requests ?: __('None'),
                'admin_url' => route('restaurant.reservations.index'),
                'restaurant_phone' => theme_option('booking_phone'),
                'restaurant_email' => theme_option('email'),
            ]);

        $emailHandler->sendUsingTemplate('admin_new_reservation');

        $emailHandler->sendUsingTemplate('reservation_confirmation', $reservation->email);

        return $response
            ->setMessage(__('Your reservation has been submitted successfully! We will contact you soon to confirm.'))
            ->setData(['reservation_id' => $reservation->getKey()]);
    }

    public function switchCurrency(string $title, Request $request, BaseHttpResponse $response)
    {
        if (empty($title)) {
            $title = $request->input('currency');
        }

        if (! $title) {
            return $response;
        }

        /**
         * @var CurrencyModel|null $currency
         */
        $currency = CurrencyModel::query()->where('title', $title)->first();

        if ($currency) {
            Currency::setApplicationCurrency($currency);
        }

        $url = URL::previous();

        if (! $url || $url === URL::current()) {
            return $response->setNextUrl(BaseHelper::getHomepageUrl());
        }

        if (Str::contains($url, ['min_price', 'max_price'])) {
            $url = preg_replace('/&min_price=[0-9]+/', '', $url);
            $url = preg_replace('/&max_price=[0-9]+/', '', $url);
        }

        return $response->setNextUrl($url);
    }
}
