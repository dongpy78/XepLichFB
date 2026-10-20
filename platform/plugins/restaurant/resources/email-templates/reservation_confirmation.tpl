{{ header }}

<div class="bb-main-content">
    <table class="bb-box" cellpadding="0" cellspacing="0">
        <tbody>
            <tr>
                <td>
                    <table cellpadding="0" cellspacing="0">
                        <tbody>
                            <tr>
                                <td class="bb-content bb-pb-0" align="center">
                                    <table class="bb-mb-lg" cellspacing="0" cellpadding="0">
                                       <tbody>
                                           <tr>
                                               <td valign="middle" align="center">
                                                   <img src="{{ site_url }}/vendor/core/plugins/restaurant/images/reservation-confirmed.png" alt="Reservation Confirmed" height="160" class="bb-img-illustration" />
                                               </td>
                                           </tr>
                                       </tbody>
                                    </table>

                                    <h1 class="bb-text-center bb-m-0">{{ 'plugins/restaurant::email.reservation-confirmation.header' | trans }}</h1>

                                    <p class="bb-text-center bb-mt-sm bb-mb-lg bb-text-muted">{{ 'plugins/restaurant::email.reservation-confirmation.content' | trans({
                                        'reservation_name': reservation_name,
                                        'reservation_date': reservation_date,
                                        'reservation_time': reservation_time,
                                        'number_of_guests': number_of_guests
                                    }) | raw }}</p>
                                </td>
                            </tr>

                            <tr>
                                <td class="bb-content">
                                    <table class="bb-details-box" cellspacing="0" cellpadding="0" style="width: 100%; border: 1px solid #e5e5e5; border-radius: 8px; margin: 20px 0;">
                                        <tbody>
                                            <tr>
                                                <td style="padding: 20px; background-color: #f8f9fa;">
                                                    <h3 style="margin: 0 0 15px 0; color: #333;">{{ 'plugins/restaurant::email.reservation_details' | trans }}</h3>
                                                    <table style="width: 100%;">
                                                        <tr>
                                                            <td style="padding: 5px 0; font-weight: bold; color: #666;">{{ 'plugins/restaurant::email.date' | trans }}:</td>
                                                            <td style="padding: 5px 0; color: #333;">{{ reservation_date }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding: 5px 0; font-weight: bold; color: #666;">{{ 'plugins/restaurant::email.time' | trans }}:</td>
                                                            <td style="padding: 5px 0; color: #333;">{{ reservation_time }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding: 5px 0; font-weight: bold; color: #666;">{{ 'plugins/restaurant::email.guests' | trans }}:</td>
                                                            <td style="padding: 5px 0; color: #333;">{{ number_of_guests }}</td>
                                                        </tr>
                                                        {% if special_requests %}
                                                        <tr>
                                                            <td style="padding: 5px 0; font-weight: bold; color: #666;">{{ 'plugins/restaurant::email.special_requests' | trans }}:</td>
                                                            <td style="padding: 5px 0; color: #333;">{{ special_requests }}</td>
                                                        </tr>
                                                        {% endif %}
                                                    </table>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <td class="bb-content bb-text-center">
                                    <table class="bb-btn bb-btn-primary" cellspacing="0" cellpadding="0">
                                        <tbody>
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ site_url }}" target="_blank">{{ 'plugins/restaurant::email.visit_website' | trans }}</a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <td class="bb-content bb-text-muted bb-text-center">
                                    <p style="margin: 20px 0 0 0; font-size: 14px; color: #666;">
                                        {{ 'plugins/restaurant::email.cancel_or_modify' | trans({'restaurant_phone': restaurant_phone, 'restaurant_email': restaurant_email}) | raw }}
                                    </p>
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