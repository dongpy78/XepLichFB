<section>
    <div class="container">
        <header>
            <h1>{{ $category->name }}</h1>
            @if($category->description)
            <p>{{ $category->description }}</p>
            @endif
        </header>

        @if($category->menuItems->count() > 0)
            <div>
                @foreach($category->menuItems as $item)
                <article style="margin-bottom: 2rem; padding-bottom: 2rem; border-bottom: 1px solid #eee;">
                    <h3><a href="{{ $item->url }}">{{ $item->name }}</a></h3>
                    
                    @if($item->image)
                    <img src="{{ RvMedia::getImageUrl($item->image, 'thumb') }}" alt="{{ $item->name }}" style="max-width: 200px;">
                    @endif
                    
                    <p>{{ $item->description }}</p>
                    
                    <div>
                        <strong>{{ __('Price') }}:</strong>
                        @if($item->has_discount)
                            <span style="text-decoration: line-through;">{{ format_price($item->price) }}</span>
                            <span>{{ format_price($item->sale_price) }}</span>
                        @else
                            {{ format_price($item->price) }}
                        @endif
                    </div>
                    
                    <div>
                        @if($item->is_vegetarian)
                        <span>✓ {{ __('Vegetarian') }}</span>
                        @endif
                        @if($item->is_vegan)
                        <span>✓ {{ __('Vegan') }}</span>
                        @endif
                        @if($item->is_gluten_free)
                        <span>✓ {{ __('Gluten Free') }}</span>
                        @endif
                    </div>
                </article>
                @endforeach
            </div>
        @else
            <p>{{ __('No menu items available in this category.') }}</p>
        @endif
    </div>
</section>