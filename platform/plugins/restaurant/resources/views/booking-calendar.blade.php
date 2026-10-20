@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="booking-calendar-wrapper">
        <x-core::alert type="info" class="mb-3">
            <div class="d-flex align-items-center">
                <i class="ti ti-info-circle fs-4 me-2"></i>
                <div>
                    <strong>{{ trans('plugins/restaurant::restaurant.calendar_tip_title') }}</strong>
                    {{ trans('plugins/restaurant::restaurant.calendar_tip_message') }}
                </div>
            </div>
        </x-core::alert>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">{{ trans('plugins/restaurant::restaurant.booking_calendar') }}</h4>
                <div class="card-header-actions">
                    <x-core::button
                        tag="a"
                        href="{{ route('restaurant.reservations.create') }}"
                        color="primary"
                        size="sm"
                        icon="ti ti-plus"
                    >
                        {{ trans('plugins/restaurant::reservation.create_reservation') }}
                    </x-core::button>
                </div>
            </div>
            <div class="card-body">
                <div id="booking-calendar"></div>
            </div>
        </div>
    </div>

    <x-core::modal
        id="booking-detail-modal"
        :title="trans('plugins/restaurant::restaurant.reservation_detail')"
        size="lg"
    >
        <div id="booking-detail-content">
            <div class="text-center">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </div>

        <x-slot name="footer">
            <x-core::button type="button" data-bs-dismiss="modal">
                {{ trans('core/base::forms.cancel') }}
            </x-core::button>
            <x-core::button
                tag="a"
                href="#"
                id="edit-booking-btn"
                color="primary"
            >
                {{ trans('core/base::forms.edit') }}
            </x-core::button>
        </x-slot>
    </x-core::modal>

    <x-core::modal
        id="create-reservation-modal"
        :title="trans('plugins/restaurant::reservation.create_reservation')"
        size="lg"
    >
        <form id="create-reservation-form">
            @csrf
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="name" class="form-label required">{{ trans('plugins/restaurant::reservation.name') }}</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="email" class="form-label required">{{ trans('plugins/restaurant::reservation.email') }}</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="phone" class="form-label">{{ trans('plugins/restaurant::reservation.phone') }}</label>
                        <input type="tel" class="form-control" id="phone" name="phone" placeholder="{{ trans('plugins/restaurant::reservation.phone_placeholder') }}">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="number_of_guests" class="form-label required">{{ trans('plugins/restaurant::reservation.number_of_guests') }}</label>
                        <input type="number" class="form-control" id="number_of_guests" name="number_of_guests" min="1" max="20" value="2" required>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="reservation_date" class="form-label required">{{ trans('plugins/restaurant::reservation.reservation_date') }}</label>
                        <input type="date" class="form-control" id="reservation_date" name="reservation_date" min="{{ \Carbon\Carbon::now()->format('Y-m-d') }}" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="reservation_time" class="form-label required">{{ trans('plugins/restaurant::reservation.reservation_time') }}</label>
                        <input type="time" class="form-control" id="reservation_time" name="reservation_time" required>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="special_requests" class="form-label">{{ trans('plugins/restaurant::reservation.special_requests') }}</label>
                <textarea class="form-control" id="special_requests" name="special_requests" rows="3" placeholder="{{ trans('plugins/restaurant::reservation.special_requests_placeholder') }}"></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label">{{ trans('plugins/restaurant::reservation.status') }}</label>
                        <select class="form-select" id="status" name="status">
                            @foreach(\Botble\Restaurant\Enums\ReservationStatusEnum::labels() as $key => $label)
                                <option value="{{ $key }}" @if($key === 'pending') selected @endif>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="admin_notes" class="form-label">{{ trans('plugins/restaurant::reservation.admin_notes') }}</label>
                        <textarea class="form-control" id="admin_notes" name="admin_notes" rows="3" placeholder="{{ trans('plugins/restaurant::reservation.admin_notes_placeholder') }}"></textarea>
                    </div>
                </div>
            </div>
        </form>
        
        <x-slot name="footer">
            <x-core::button type="button" data-bs-dismiss="modal">
                {{ trans('core/base::forms.cancel') }}
            </x-core::button>
            <x-core::button
                type="submit"
                form="create-reservation-form"
                color="primary"
            >
                {{ trans('plugins/restaurant::reservation.create_reservation') }}
            </x-core::button>
        </x-slot>
    </x-core::modal>

    <script>
        window.bookingCalendarConfig = {
            eventsUrl: '{{ route('restaurant.booking-calendar.records') }}',
            detailUrl: '{{ route('restaurant.booking-calendar.detail', ['id' => '__ID__']) }}',
            createReservationUrl: '{{ route('restaurant.booking-calendar.store-reservation') }}',
            locale: '{{ app()->getLocale() }}',
        };
    </script>
@endsection
