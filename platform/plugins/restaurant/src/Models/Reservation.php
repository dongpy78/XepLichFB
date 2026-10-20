<?php

namespace Botble\Restaurant\Models;

use Botble\Base\Casts\SafeContent;
use Botble\Base\Models\BaseModel;
use Botble\Restaurant\Enums\ReservationStatusEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Reservation extends BaseModel
{
    protected $table = 'restaurant_reservations';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'reservation_date',
        'reservation_time',
        'number_of_guests',
        'special_requests',
        'status',
        'admin_notes',
    ];

    protected $casts = [
        'status' => ReservationStatusEnum::class,
        'name' => SafeContent::class,
        'special_requests' => SafeContent::class,
        'admin_notes' => SafeContent::class,
        'reservation_date' => 'date',
        'reservation_time' => 'datetime:H:i',
        'number_of_guests' => 'integer',
    ];

    protected function fullReservationDateTime(): Attribute
    {
        return Attribute::make(
            get: fn () => Carbon::parse($this->reservation_date->format('Y-m-d') . ' ' . $this->reservation_time)
        );
    }

    protected function formattedDateTime(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->full_reservation_date_time->format('M d, Y \a\t g:i A')
        );
    }

    public function scopePending($query)
    {
        return $query->where('status', ReservationStatusEnum::PENDING);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', ReservationStatusEnum::CONFIRMED);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('reservation_date', '>=', today())
            ->orderBy('reservation_date')
            ->orderBy('reservation_time');
    }

    public function scopePast($query)
    {
        return $query->where('reservation_date', '<', today())
            ->orderBy('reservation_date', 'desc')
            ->orderBy('reservation_time', 'desc');
    }
}
