<!DOCTYPE html>
<html {!! Theme::htmlAttributes() !!}>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, user-scalable=1" name="viewport"/>
        <meta name="format-detection" content="telephone=no">
        <meta name="mobile-web-app-capable" content="yes">

        @php(Theme::set('headerMeta', Theme::partial('header-meta')))

        {!! Theme::header() !!}

        <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:ital,wght@0,300..900;1,300..900&amp;family=JetBrains+Mono:wght@400..700&amp;family=Outfit:wght@400..700&amp;display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap">
    <script src="https://cdn.tailwindcss.com"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "inverse-surface": "#2d3133",
                        "on-tertiary-fixed-variant": "#005137",
                        "error-container": "#ffdad6",
                        "on-primary-container": "#768dad",
                        "secondary-fixed-dim": "#f5bc7e",
                        "on-secondary-fixed": "#2b1700",
                        "surface-container-high": "#e6e8ea",
                        "inverse-primary": "#b0c8eb",
                        "on-surface-variant": "#43474d",
                        "primary-fixed": "#d2e4ff",
                        "on-tertiary-container": "#1a9e70",
                        "surface-variant": "#e0e3e5",
                        "on-error-container": "#93000a",
                        "on-error": "#ffffff",
                        "on-primary": "#ffffff",
                        "surface-bright": "#f7f9fb",
                        "primary-fixed-dim": "#b0c8eb",
                        "surface-container-low": "#f2f4f6",
                        background: "#f7f9fb",
                        "outline-variant": "#c4c6ce",
                        "on-background": "#191c1e",
                        surface: "#f7f9fb",
                        outline: "#74777e",
                        "secondary-container": "#fec485",
                        "on-surface": "#191c1e",
                        "tertiary-container": "#002a1b",
                        "on-secondary": "#ffffff",
                        "inverse-on-surface": "#eff1f3",
                        "on-secondary-container": "#794f1b",
                        "tertiary-fixed": "#85f8c4",
                        "on-tertiary": "#ffffff",
                        "surface-container": "#eceef0",
                        tertiary: "#001209",
                        primary: "#000f22",
                        "tertiary-fixed-dim": "#68dba9",
                        "secondary-fixed": "#ffddbb",
                        "primary-container": "#0a2540",
                        "surface-dim": "#d8dadc",
                        "on-primary-fixed-variant": "#314865",
                        "on-primary-fixed": "#001c37",
                        error: "#ba1a1a",
                        "surface-tint": "#49607e",
                        "on-tertiary-fixed": "#002114",
                        "on-secondary-fixed-variant": "#653e0b",
                        "surface-container-lowest": "#ffffff",
                        secondary: "#805521",
                    },
                    borderRadius: {
                        DEFAULT: "0.125rem",
                        lg: "0.25rem",
                        xl: "0.5rem",
                        full: "0.75rem",
                    },
                    spacing: {
                        "space-md": "0.75rem",
                        "space-xs": "0.25rem",
                        "space-lg": "1.25rem",
                        "space-sm": "0.375rem",
                        gutter: "0.75rem",
                        "space-xl": "2rem",
                        margin: "1.5rem",
                    },
                    fontFamily: {
                        "body-lg": ["Hanken Grotesk"],
                        "tabular-data": ["JetBrains Mono"],
                        "label-md": ["Hanken Grotesk"],
                        "headline-xl": ["Outfit"],
                        "label-sm": ["JetBrains Mono"],
                        "headline-sm": ["Outfit"],
                        "body-sm": ["Hanken Grotesk"],
                        "headline-lg": ["Outfit"],
                        "body-md": ["Hanken Grotesk"],
                        "headline-md": ["Outfit"],
                    },
                    fontSize: {
                        "body-lg": ["15px", {
                            lineHeight: "22px",
                            fontWeight: "400"
                        }],
                        "tabular-data": [
                            "12px",
                            {
                                lineHeight: "16px",
                                letterSpacing: "-0.02em",
                                fontWeight: "500",
                            },
                        ],
                        "label-md": [
                            "12px",
                            {
                                lineHeight: "16px",
                                letterSpacing: "0.02em",
                                fontWeight: "600",
                            },
                        ],
                        "headline-xl": [
                            "32px",
                            {
                                lineHeight: "40px",
                                letterSpacing: "-0.02em",
                                fontWeight: "600",
                            },
                        ],
                        "label-sm": [
                            "10px",
                            {
                                lineHeight: "14px",
                                letterSpacing: "0.04em",
                                fontWeight: "600",
                            },
                        ],
                        "headline-sm": [
                            "16px",
                            {
                                lineHeight: "24px",
                                letterSpacing: "-0.005em",
                                fontWeight: "600",
                            },
                        ],
                        "body-sm": ["12px", {
                            lineHeight: "16px",
                            fontWeight: "400"
                        }],
                        "headline-lg": [
                            "24px",
                            {
                                lineHeight: "32px",
                                letterSpacing: "-0.015em",
                                fontWeight: "600",
                            },
                        ],
                        "body-md": ["13px", {
                            lineHeight: "18px",
                            fontWeight: "400"
                        }],
                        "headline-md": [
                            "20px",
                            {
                                lineHeight: "28px",
                                letterSpacing: "-0.01em",
                                fontWeight: "600",
                            },
                        ],
                    },
                },
            },
        };
    </script>
    <!-- Thêm jQuery UI CSS từ CDN-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/themes/base/jquery-ui.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightgallery/1.6.12/css/lightgallery.min.css">
    <!-- Thêm jQuery UI JS từ CDN-->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
    </head>
    <body class="bg-surface font-body-md text-body-md text-on-surface antialiased" {!! Theme::bodyAttributes() !!}>
    {!! apply_filters(THEME_FRONT_BODY, null) !!}
    <header class="header" id="header">
        <div class="header-wrap d-none d-sm-block">
            <nav class="nav-top">
                <div class="container">
                    <div class="row">
                        @if ($socialLinks = Theme::getSocialLinks())
                            <div class="col-sm-4 d-flex align-items-center" style="min-height: 40px;">
                                <ul class="social social--simple">
                                    @foreach($socialLinks as $socialLink)
                                        @continue(! $icon = $socialLink->getIconHtml())

                                        <li>
                                            <a {{ $socialLink->getAttributes() }}>
                                                {{ $icon }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="col-sm-8 d-flex align-items-center justify-content-end nav-top-right">
                            @if (is_plugin_active('member') && setting('member_enabled_login', true))
                                <ul class="d-flex">
                                    @if (auth('member')->check())
                                        <li><a href="{{ route('public.member.dashboard') }}" rel="nofollow"><img src="{{ auth('member')->user()->avatar_thumb_url }}" class="img-circle" width="20" alt="{{ auth('member')->user()->name }}" loading="lazy"> &nbsp;<span>{{ auth('member')->user()->name }}</span></a></li>
                                        <li><a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" rel="nofollow">{!! BaseHelper::renderIcon('ti ti-login-2') !!} {{ __('Logout') }}</a></li>
                                    @else
                                        <li><a href="{{ route('public.member.login') }}" rel="nofollow">{!! BaseHelper::renderIcon('ti ti-login-2') !!} {{ __('Login') }}</a></li>
                                    @endif
                                </ul>
                                @if (auth('member')->check())
                                    <form id="logout-form" action="{{ route('public.member.logout') }}" method="POST" style="display: none;">
                                        @csrf
                                    </form>
                                @endif
                            @endif

                            <div class="language-wrapper d-sm-inline-block">
                                {!! apply_filters('language_switcher') !!}
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
        </div>
    </header>
    <header data-sticky="false" data-sticky-checkpoint="200" data-responsive="991" class="page-header page-header--light">
        <div class="container">
            <div class="page-header__left">
                <a href="{{ BaseHelper::getHomepageUrl() }}" class="page-logo">
                    {{ Theme::getLogoImage(maxHeight: 50) }}
                </a>
            </div>
            <div class="page-header__right">
                <div class="navigation-toggle navigation-toggle--dark" style="display: none"><span></span></div>
                <div class="float-start">
                    <div class="search-btn c-search-toggler">
                        {!! BaseHelper::renderIcon('ti ti-search', attributes: ['class' => 'close-search']) !!}
                    </div>
                    <nav class="navigation navigation--light navigation--fade navigation--fadeLeft">
                        {!!
                            Menu::renderMenuLocation('main-menu', [
                                'options' => ['class' => 'menu sub-menu--slideLeft'],
                                'view'    => 'main-menu',
                            ])
                        !!}

                        @if (is_plugin_active('member'))
                            <ul class="menu sub-menu--slideLeft d-block d-sm-none">
                                @if (auth('member')->check())
                                    <li class="menu-item">
                                        <a href="{{ route('public.member.dashboard') }}" rel="nofollow">
                                            <img src="{{ auth('member')->user()->avatar_thumb_url }}" class="img-circle" width="20" alt="{{ auth('member')->user()->name }}" loading="lazy">
                                             &nbsp;<span>{{ auth('member')->user()->name }}</span>
                                        </a>
                                    </li>
                                    <li class="menu-item"><a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" rel="nofollow">{!! BaseHelper::renderIcon('ti ti-logout-2') !!} {{ __('Logout') }}</a></li>
                                @else
                                    <li class="menu-item"><a href="{{ route('public.member.login') }}" rel="nofollow">{!! BaseHelper::renderIcon('ti ti-login-2') !!} {{ __('Login') }}</a></li>
                                @endif
                            </ul>
                        @endif

                        <li class="language-wrapper d-block d-sm-none">
                            {!! apply_filters('language_switcher') !!}
                        </li>
                    </nav>

                </div>
                <div class="clearfix"></div>
            </div>
            <div class="clearfix"></div>
        </div>
        @if (is_plugin_active('blog'))
            <div class="super-search hide" data-search-url="{{ route('public.ajax.search') }}">
                <form class="quick-search" action="{{ route('public.search') }}">
                    <input type="text" name="q" placeholder="{{ __('Type to search...') }}" class="form-control search-input" autocomplete="off">
                    <span class="close-search">&times;</span>
                </form>
                <div class="search-result"></div>
            </div>
        @endif
    </header>
    <div id="page-wrap">
