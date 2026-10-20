@if ($copyright = Theme::getSiteCopyright())
    <footer class="d-block d-lg-none text-center py-3">
        <p class="text-muted">{!! $copyright !!}</p>
    </footer>
@endif

<script src="{{ asset('vendor/core/plugins/member/js/dashboard/script.js') }}"></script>
