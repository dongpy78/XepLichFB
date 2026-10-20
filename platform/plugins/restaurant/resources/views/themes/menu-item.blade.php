<article>
    <div class="container">
        <header>
            <h1>{{ $menuItem->name }}</h1>
            @if($menuItem->category)
            <p>{{ __('Category') }}: {{ $menuItem->category->name }}</p>
            @endif
        </header>

        @if($menuItem->image)
        <div>
            <img src="{{ RvMedia::getImageUrl($menuItem->image) }}" alt="{{ $menuItem->name }}" style="max-width: 100%; height: auto;">
        </div>
        @endif

        <div>
            <p>{{ $menuItem->description }}</p>

            @if($menuItem->ingredients)
            <h3>{{ __('Ingredients') }}</h3>
            <p>{{ $menuItem->ingredients }}</p>
            @endif

            <div>
                <strong>{{ __('Price') }}:</strong>
                @if($menuItem->has_discount)
                    <span style="text-decoration: line-through;">{{ format_price($menuItem->price) }}</span>
                    <span>{{ format_price($menuItem->sale_price) }}</span>
                @else
                    {{ format_price($menuItem->price) }}
                @endif
            </div>

            @if($menuItem->calories)
            <div>
                <strong>{{ __('Calories') }}:</strong> {{ $menuItem->calories }}
            </div>
            @endif

            <div>
                @if($menuItem->is_vegetarian)
                <span>✓ {{ __('Vegetarian') }}</span>
                @endif
                @if($menuItem->is_vegan)
                <span>✓ {{ __('Vegan') }}</span>
                @endif
                @if($menuItem->is_gluten_free)
                <span>✓ {{ __('Gluten Free') }}</span>
                @endif
            </div>
        </div>
    </div>
</article>