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
                                                   {% if reservation_status == 'approved' %}
                                                   <img src="{{ site_url }}/vendor/core/plugins/restaurant/images/reservation-approved.png" alt="Reservation Approved" height="160" class="bb-img-illustration" />
                                                   {% elseif reservation_status == 'cancelled' %}
                                                   <img src="{{ site_url }}/vendor/core/plugins/restaurant/images/reservation-cancelled.png" alt="Reservation Cancelled" height="160" class="bb-img-illustration" />
                                                   {% else %}
                                                   <img src="{{ site_url }}/vendor/core/plugins/restaurant/images/reservation-update.png" alt="Reservation Update" height="160" class="bb-img-illustration" />
                                                   {% endif %}
                                               </td>
                                           </tr>
                                       </tbody>
                                    </table>

                                    {% if reservation_status == 'approved' %}
                                    <h1 class="bb-text-center bb-m-0" style="color: #28a745;">{{ 'plugins/restaurant::email.status_update.approved_title' | trans }}</h1>
                                    <p class="bb-text-center bb-mt-sm bb-mb-lg bb-text-muted">{{ 'plugins/restaurant::email.status_update.approved_message' | trans }}</p>
                                    {% elseif reservation_status == 'cancelled' %}
                                    <h1 class="bb-text-center bb-m-0" style="color: #dc3545;">{{ 'plugins/restaurant::email.status_update.cancelled_title' | trans }}</h1>
                                    <p class="bb-text-center bb-mt-sm bb-mb-lg bb-text-muted">{{ 'plugins/restaurant::email.status_update.cancelled_message' | trans }}</p>
                                    {% else %}
                                    <h1 class="bb-text-center bb-m-0">{{ 'plugins/restaurant::email.status_update.default_title' | trans }}</h1>
                                    <p class="bb-text-center bb-mt-sm bb-mb-lg bb-text-muted">{{ 'plugins/restaurant::email.status_update.default_message' | trans }}</p>
                                    {% endif %}
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
                                                            <td style="padding: 5px 0; font-weight: bold; color: #666;">{{ 'plugins/restaurant::email.status' | trans }}:</td>
                                                            <td style="padding: 5px 0; color: #333; text-transform: capitalize;">
                                                                {% if reservation_status == 'approved' %}
                                                                <span style="color: #28a745; font-weight: bold;">✓ {{ 'plugins/restaurant::email.confirmed' | trans }}</span>
                                                                {% elseif reservation_status == 'cancelled' %}
                                                                <span style="color: #dc3545; font-weight: bold;">✗ {{ 'plugins/restaurant::email.cancelled' | trans }}</span>
                                                                {% else %}
                                                                <span style="font-weight: bold;">{{ reservation_status }}</span>
                                                                {% endif %}
                                                            </td>
                                                        </tr>
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
                                                    </table>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            {% if admin_notes %}
                            <tr>
                                <td class="bb-content">
                                    <table class="bb-notes-box" cellspacing="0" cellpadding="0" style="width: 100%; border: 1px solid #d1ecf1; border-radius: 8px; margin: 20px 0; background-color: #d1ecf1;">
                                        <tbody>
                                            <tr>
                                                <td style="padding: 20px; background-color: #d1ecf1;">
                                                    <h4 style="margin: 0 0 10px 0; color: #0c5460;">{{ 'plugins/restaurant::email.note_from_restaurant' | trans }}</h4>
                                                    <p style="margin: 0; color: #0c5460;">{{ admin_notes }}</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            {% endif %}

                            <tr>
                                <td class="bb-content bb-text-center">
                                    {% if reservation_status == 'approved' %}
                                    <table class="bb-btn bb-btn-success" cellspacing="0" cellpadding="0">
                                        <tbody>
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ site_url }}" target="_blank">{{ 'plugins/restaurant::email.visit_website' | trans }}</a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    {% elseif reservation_status == 'cancelled' %}
                                    <table class="bb-btn bb-btn-primary" cellspacing="0" cellpadding="0">
                                        <tbody>
                                            <tr>
                                                <td align="center">
                                                    <a href="{{ site_url }}" target="_blank">{{ 'plugins/restaurant::email.make_new_reservation' | trans }}</a>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    {% endif %}
                                </td>
                            </tr>

                            <tr>
                                <td class="bb-content bb-text-muted bb-text-center">
                                    <p style="margin: 20px 0 0 0; font-size: 14px; color: #666;">
                                        {{ 'plugins/restaurant::email.contact_us' | trans({'restaurant_phone': restaurant_phone, 'restaurant_email': restaurant_email}) | raw }}
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