<div class="reservation-form-wrapper">
    <h3 class="reservation-form-title mb-4">{{ __('Make a Reservation') }}</h3>
    
    <form action="{{ route('public.restaurant.reservation') }}" method="POST" class="reservation-form" id="reservationForm">
        @csrf
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="name" class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>
            
            <div class="col-md-6 mb-3">
                <label for="email" class="form-label">{{ __('Email') }} <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="phone" class="form-label">{{ __('Phone') }}</label>
                <input type="tel" class="form-control" id="phone" name="phone">
            </div>
            
            <div class="col-md-6 mb-3">
                <label for="number_of_guests" class="form-label">{{ __('Number of Guests') }} <span class="text-danger">*</span></label>
                <select class="form-control" id="number_of_guests" name="number_of_guests" required>
                    @for($i = 1; $i <= 20; $i++)
                        <option value="{{ $i }}">{{ $i }} {{ $i == 1 ? __('Guest') : __('Guests') }}</option>
                    @endfor
                </select>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="reservation_date" class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="reservation_date" name="reservation_date" 
                       min="{{ now()->format('Y-m-d') }}" required>
            </div>
            
            <div class="col-md-6 mb-3">
                <label for="reservation_time" class="form-label">{{ __('Time') }} <span class="text-danger">*</span></label>
                <input type="time" class="form-control" id="reservation_time" name="reservation_time" required>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="special_requests" class="form-label">{{ __('Special Requests') }}</label>
            <textarea class="form-control" id="special_requests" name="special_requests" rows="3" 
                      placeholder="{{ __('Any special requests or dietary requirements...') }}"></textarea>
        </div>
        
        <div class="text-center">
            <button type="submit" class="btn btn-primary btn-lg">{{ __('Submit Reservation') }}</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('reservationForm');
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = '{{ __("Submitting...") }}';
        
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert(data.message || '{{ __("An error occurred. Please try again.") }}');
            } else {
                alert(data.message || '{{ __("Reservation submitted successfully!") }}');
                form.reset();
            }
        })
        .catch(error => {
            alert('{{ __("An error occurred. Please try again.") }}');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        });
    });
});
</script>