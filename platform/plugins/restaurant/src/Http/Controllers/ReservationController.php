<?php

namespace Botble\Restaurant\Http\Controllers;

use Botble\Base\Events\BeforeEditContentEvent;
use Botble\Base\Events\CreatedContentEvent;
use Botble\Base\Events\DeletedContentEvent;
use Botble\Base\Events\UpdatedContentEvent;
use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\EmailHandler;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Restaurant\Forms\ReservationForm;
use Botble\Restaurant\Http\Requests\ReservationRequest;
use Botble\Restaurant\Models\Reservation;
use Botble\Restaurant\Tables\ReservationTable;
use Exception;
use Illuminate\Http\Request;

class ReservationController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/restaurant::restaurant.menu_name'))
            ->add(trans('plugins/restaurant::reservation.reservations'), route('restaurant.reservations.index'));
    }

    public function index(ReservationTable $table)
    {
        $this->pageTitle(trans('plugins/restaurant::reservation.reservations'));

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle(trans('plugins/restaurant::reservation.create_reservation'));

        return ReservationForm::create()->renderForm();
    }

    public function store(ReservationRequest $request, BaseHttpResponse $response)
    {
        $reservation = Reservation::query()->create($request->validated());

        event(new CreatedContentEvent(RESERVATION_MODULE_SCREEN_NAME, $request, $reservation));

        return $response
            ->setPreviousUrl(route('restaurant.reservations.index'))
            ->setNextUrl(route('restaurant.reservations.edit', $reservation->id))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function show(int|string $id)
    {
        $reservation = Reservation::query()->findOrFail($id);

        $this->pageTitle(trans('plugins/restaurant::reservation.view_reservation', ['id' => $reservation->id]));

        return view('plugins/restaurant::reservations.show', compact('reservation'));
    }

    public function edit(int|string $id, Request $request)
    {
        /**
         * @var Reservation $reservation
         */
        $reservation = Reservation::query()->findOrFail($id);

        event(new BeforeEditContentEvent($request, $reservation));

        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $reservation->name]));

        return ReservationForm::createFromModel($reservation)->renderForm();
    }

    public function update(int|string $id, ReservationRequest $request, BaseHttpResponse $response)
    {
        $reservation = Reservation::query()->findOrFail($id);
        $oldStatus = $reservation->status;

        $reservation->update($request->validated());

        event(new UpdatedContentEvent(RESERVATION_MODULE_SCREEN_NAME, $request, $reservation));

        // Send status update email to customer if status changed
        if ($oldStatus !== $reservation->status && $reservation->email) {
            EmailHandler::setModule(RESTAURANT_MODULE_SCREEN_NAME)
                ->setVariableValues([
                    'reservation_name' => $reservation->name,
                    'reservation_email' => $reservation->email,
                    'reservation_date' => $reservation->reservation_date->format('M d, Y'),
                    'reservation_time' => $reservation->reservation_time,
                    'number_of_guests' => $reservation->number_of_guests,
                    'reservation_status' => $reservation->status,
                    'admin_notes' => $reservation->admin_notes ?: '',
                    'restaurant_phone' => theme_option('booking_phone'),
                    'restaurant_email' => theme_option('email'),
                    'site_url' => BaseHelper::getHomepageUrl(),
                ])
                ->sendUsingTemplate('reservation_status_update', $reservation->email);
        }

        return $response
            ->setPreviousUrl(route('restaurant.reservations.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function destroy(int|string $id, Request $request, BaseHttpResponse $response)
    {
        try {
            $reservation = Reservation::query()->findOrFail($id);

            $reservation->delete();

            event(new DeletedContentEvent(RESERVATION_MODULE_SCREEN_NAME, $request, $reservation));

            return $response->setMessage(trans('core/base::notices.delete_success_message'));
        } catch (Exception $exception) {
            return $response
                ->setError()
                ->setMessage($exception->getMessage());
        }
    }
}
