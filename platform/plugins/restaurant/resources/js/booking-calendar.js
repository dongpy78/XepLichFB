document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('booking-calendar');
    
    if (!calendarEl) {
        return;
    }

    const config = window.bookingCalendarConfig || {};
    
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        locale: config.locale || 'en',
        height: 650,
        contentHeight: 600,
        aspectRatio: 1.8,
        navLinks: true,
        editable: false,
        dayMaxEvents: true,
        selectable: true,
        selectMirror: true,
        events: function(info, successCallback, failureCallback) {
            fetch(config.eventsUrl + '?start=' + info.startStr + '&end=' + info.endStr)
                .then(response => response.json())
                .then(data => successCallback(data))
                .catch(error => {
                    console.error('Error fetching events:', error);
                    failureCallback(error);
                });
        },
        dateClick: function(info) {
            // Open create reservation modal with the selected date
            const modal = document.getElementById('create-reservation-modal');
            if (modal) {
                const dateInput = modal.querySelector('#reservation_date');
                if (dateInput) {
                    dateInput.value = info.dateStr;
                }
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        },
        eventClick: function(info) {
            const modal = new bootstrap.Modal(document.getElementById('booking-detail-modal'));
            const contentEl = document.getElementById('booking-detail-content');
            const editBtn = document.getElementById('edit-booking-btn');
            
            // Show loading
            contentEl.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            // Fetch booking details
            const detailUrl = config.detailUrl.replace('__ID__', info.event.id);
            fetch(detailUrl)
                .then(response => response.json())
                .then(data => {
                    contentEl.innerHTML = data.html;
                    editBtn.href = data.editUrl;
                })
                .catch(error => {
                    console.error('Error fetching booking details:', error);
                    contentEl.innerHTML = '<div class="alert alert-danger">Error loading booking details</div>';
                });
            
            modal.show();
        },
        eventDidMount: function(info) {
            // Add tooltip with basic info
            const props = info.event.extendedProps;
            if (props) {
                const tooltipContent = `
                    <strong>${props.name}</strong><br>
                    ${props.guests} guests<br>
                    Status: ${props.status}
                `;
                
                info.el.setAttribute('data-bs-toggle', 'tooltip');
                info.el.setAttribute('data-bs-html', 'true');
                info.el.setAttribute('data-bs-placement', 'top');
                info.el.setAttribute('title', tooltipContent);
                
                new bootstrap.Tooltip(info.el);
            }
        }
    });

    calendar.render();
    
    // Refresh calendar every 5 minutes
    setInterval(function() {
        calendar.refetchEvents();
    }, 300000);
    
    // Handle reservation form submission
    const reservationForm = document.getElementById('create-reservation-form');
    if (reservationForm) {
        reservationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(reservationForm);
            // Find the submit button in the modal footer
            const submitBtn = document.querySelector('#create-reservation-modal button[form="create-reservation-form"]');
            
            if (!submitBtn) {
                console.error('Submit button not found');
                return;
            }
            
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating...';
            
            fetch(config.createReservationUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    // Show error message
                    Botble.showError(data.message || 'An error occurred');
                } else {
                    // Success
                    Botble.showSuccess(data.message || 'Reservation created successfully');
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('create-reservation-modal'));
                    if (modal) {
                        modal.hide();
                    }
                    
                    // Reset form
                    reservationForm.reset();
                    
                    // Refresh calendar events
                    calendar.refetchEvents();
                }
            })
            .catch(error => {
                console.error('Error creating reservation:', error);
                Botble.showError('An error occurred while creating the reservation');
            })
            .finally(() => {
                // Restore button state
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });
        });
    }
});