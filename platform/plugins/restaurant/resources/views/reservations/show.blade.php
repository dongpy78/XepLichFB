@extends(BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle">
                        {{ trans('plugins/restaurant::reservation.reservations') }}
                    </div>
                    <h2 class="page-title">
                        {{ trans('plugins/restaurant::reservation.view_reservation', ['id' => $reservation->id]) }}
                    </h2>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="{{ route('restaurant.reservations.index') }}" class="btn btn-ghost-dark">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-arrow-left" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                                <line x1="5" y1="12" x2="11" y2="18"/>
                                <line x1="5" y1="12" x2="11" y2="6"/>
                            </svg>
                            {{ trans('plugins/restaurant::reservation.back') }}
                        </a>
                        <a href="{{ route('restaurant.reservations.edit', $reservation->id) }}" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-edit" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                <path d="M7 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-1"/>
                                <path d="M20.385 6.585a2.1 2.1 0 0 0-2.97-2.97L9 12v3h3l8.385-8.415z"/>
                                <path d="M16 5l3 3"/>
                            </svg>
                            {{ trans('plugins/restaurant::reservation.edit') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="row row-deck row-cards">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">{{ trans('plugins/restaurant::reservation.reservation') }} {{ trans('plugins/restaurant::reservation.information') }}</h3>
                            <div class="card-actions">
                                {!! $reservation->status->toHtml() !!}
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.id') }}</label>
                                                    <div class="text-secondary">#{{ $reservation->id }}</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.name') }}</label>
                                                    <div class="fw-bold">{{ $reservation->name }}</div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.email') }}</label>
                                                    <div>
                                                        <a href="mailto:{{ $reservation->email }}" class="text-decoration-none">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                                <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                                                <polyline points="3,7 12,13 21,7"/>
                                                            </svg>
                                                            {{ $reservation->email }}
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.phone') }}</label>
                                                    <div>
                                                        @if($reservation->phone)
                                                            <a href="tel:{{ $reservation->phone }}" class="text-decoration-none">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                                    <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                                                    <path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>
                                                                </svg>
                                                                {{ $reservation->phone }}
                                                            </a>
                                                        @else
                                                            <span class="text-muted">{{ trans('plugins/restaurant::restaurant.not_available') }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-4">
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.reservation_date') }}</label>
                                                    <div class="fw-bold text-primary">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                            <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                                            <rect x="4" y="5" width="16" height="16" rx="2"/>
                                                            <line x1="16" y1="3" x2="16" y2="7"/>
                                                            <line x1="8" y1="3" x2="8" y2="7"/>
                                                            <line x1="4" y1="11" x2="20" y2="11"/>
                                                        </svg>
                                                        {{ $reservation->reservation_date->format('M d, Y') }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.reservation_time') }}</label>
                                                    <div class="fw-bold text-primary">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                            <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                                            <circle cx="12" cy="12" r="9"/>
                                                            <polyline points="12,7 12,12 15,15"/>
                                                        </svg>
                                                        {{ $reservation->reservation_time }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.number_of_guests') }}</label>
                                                    <div class="fw-bold">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                                            <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                                            <circle cx="9" cy="7" r="4"/>
                                                            <path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                                                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                                            <path d="M21 21v-2a4 4 0 0 0-3-3.85"/>
                                                        </svg>
                                                        {{ $reservation->number_of_guests }} {{ $reservation->number_of_guests > 1 ? trans('plugins/restaurant::reservation.guests') : trans('plugins/restaurant::reservation.guests') }}
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-3">
                                                    <label class="form-label">{{ trans('plugins/restaurant::reservation.created_at') }}</label>
                                                    <div class="text-secondary">{{ $reservation->created_at->format('M d, Y H:i') }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if($reservation->special_requests)
                            <div class="mb-4">
                                <h4 class="card-title mb-3">{{ trans('plugins/restaurant::reservation.special_requests') }}</h4>
                                <div class="alert alert-info">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                        <circle cx="12" cy="12" r="9"/>
                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                    </svg>
                                    {{ $reservation->special_requests }}
                                </div>
                            </div>
                            @endif

                            @if($reservation->admin_notes)
                            <div class="mb-4">
                                <h4 class="card-title mb-3">{{ trans('plugins/restaurant::reservation.admin_notes') }}</h4>
                                <div class="alert alert-warning">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="m0 0h24v24H0z" fill="none"/>
                                        <path d="M12 9v2m0 4v.01"/>
                                        <path d="M5 19h14a2 2 0 0 0 1.84-2.75L13.74 4a2 2 0 0 0-3.5 0l-7.1 12.25A2 2 0 0 0 4.89 19"/>
                                    </svg>
                                    {{ $reservation->admin_notes }}
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="modal modal-blur fade" id="delete-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="modal-title">{{ trans('plugins/restaurant::restaurant.delete_confirm_title') }}</div>
                    <div>{{ trans('plugins/restaurant::restaurant.delete_confirm_message') }}</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal">{{ trans('core/base::forms.cancel') }}</button>
                    <form method="POST" action="{{ route('restaurant.reservations.destroy', $reservation->id) }}" style="display: inline;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">{{ trans('plugins/restaurant::restaurant.delete') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@stop
