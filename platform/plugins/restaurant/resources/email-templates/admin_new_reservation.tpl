{{ header }}

<div class="bb-main-content">
    <table class="bb-box" cellpadding="0" cellspacing="0">
        <tbody>
            <tr>
                <td>
                    <table cellpadding="0" cellspacing="0">
                        <tbody>
                            <tr>
                                <td class="bb-content" align="center">
                                    <table class="bb-mb-lg" cellspacing="0" cellpadding="0">
                                        <tbody>
                                            <tr>
                                                <td valign="middle" align="center">
                                                    <img src="{{ site_url }}/vendor/core/plugins/restaurant/images/reservation.png" alt="Reservation Icon" height="160" class="bb-img-illustration" />
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <h1 class="bb-text-center bb-m-0">{{ 'plugins/restaurant::email.new-reservation.header' | trans }}</h1>

                                    <p class="bb-text-center bb-mt-sm bb-mb-lg bb-text-muted">{{ 'plugins/restaurant::email.new-reservation.content' | trans({
                                        'reservation_name': reservation_name,
                                        'reservation_email': reservation_email,
                                        'reservation_phone': reservation_phone,
                                        'reservation_date': reservation_date,
                                        'reservation_time': reservation_time,
                                        'number_of_guests': number_of_guests,
                                        'special_requests': special_requests
                                    }) | raw }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td class="bb-content bb-text-center">
                                    <table class="bb-btn bb-btn-primary" cellspacing="0" cellpadding="0">
                                        <tbody>
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ admin_url }}/restaurant/reservations" target="_blank">{{ 'plugins/restaurant::email.view_reservation' | trans }}</a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{ footer }}