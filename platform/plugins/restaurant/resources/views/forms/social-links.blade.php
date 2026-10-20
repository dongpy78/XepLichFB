@php
    $socialLinks = old('social_links', $model->social_links ?? []);
    $platforms = [
        'facebook' => 'Facebook',
        'twitter' => 'X (Twitter)',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube',
        'pinterest' => 'Pinterest',
        'dribbble' => 'Dribbble',
    ];
@endphp

<div class="form-group">
    <p class="mb-3">{{ trans('plugins/restaurant::chef.forms.social_links_help') }}</p>
    
    @foreach($platforms as $key => $label)
        <div class="mb-3">
            <label for="social_links_{{ $key }}" class="form-label">{{ $label }}</label>
            <input 
                type="url" 
                class="form-control" 
                id="social_links_{{ $key }}" 
                name="social_links[{{ $key }}]" 
                value="{{ $socialLinks[$key] ?? '' }}"
                placeholder="https://{{ $key }}.com/username"
            >
        </div>
    @endforeach
</div>