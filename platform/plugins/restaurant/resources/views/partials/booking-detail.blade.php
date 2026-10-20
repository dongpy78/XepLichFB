<div class="table-responsive">
    <table class="table table-bordered">
        <tbody>
            <tr>
                <th width="30%">{{ trans('plugins/restaurant::restaurant.form.name') }}</th>
                <td>{{ $reservation->name }}</td>
            </tr>
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.email') }}</th>
                <td>{{ $reservation->email }}</td>
            </tr>
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.phone') }}</th>
                <td>{{ $reservation->phone }}</td>
            </tr>
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.reservation_date') }}</th>
                <td>{{ $reservation->reservation_date->format('Y-m-d') }}</td>
            </tr>
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.reservation_time') }}</th>
                <td>
                    @if($reservation->reservation_time instanceof \Carbon\Carbon)
                        {{ $reservation->reservation_time->format('H:i') }}
                    @elseif(str_contains($reservation->reservation_time, ' '))
                        {{ \Carbon\Carbon::parse($reservation->reservation_time)->format('H:i') }}
                    @else
                        {{ $reservation->reservation_time }}
                    @endif
                </td>
            </tr>
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.guests') }}</th>
                <td>{{ $reservation->number_of_guests }}</td>
            </tr>
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.status') }}</th>
                <td>{!! $reservation->status->toHtml() !!}</td>
            </tr>
            @if($reservation->special_requests)
            <tr>
                <th>{{ trans('plugins/restaurant::restaurant.form.special_requests') }}</th>
                <td>{{ $reservation->special_requests }}</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>
