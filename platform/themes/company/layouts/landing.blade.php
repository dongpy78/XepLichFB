<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ Theme::get('landingTitle', 'Phần mềm xếp lịch & phân ca nhân sự') }}</title>
    <meta name="description" content="{{ Theme::get('landingDescription', '') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&family=Outfit:wght@500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: { DEFAULT: '#0a2540', deep: '#000f22', soft: '#1e3a8a', ink: '#768dad' },
                        gold: { DEFAULT: '#e0a96d', deep: '#92400e', wash: '#fdf3e7' },
                        surface: '#f7f9fb',
                        line: '#e2e8f0',
                        ink: { DEFAULT: '#191c1e', muted: '#43474d' },
                    },
                    fontFamily: {
                        display: ['Outfit', 'sans-serif'],
                        body: ['Hanken Grotesk', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    boxShadow: {
                        float: '0 8px 24px -4px rgba(10,37,64,.08), 0 2px 6px -1px rgba(10,37,64,.04)',
                        frame: '0 30px 60px -20px rgba(0,15,34,.45)',
                    },
                },
            },
        };
    </script>
    <style>
        .material-symbols-outlined { font-size: 22px; line-height: 1; vertical-align: middle; }
        html { scroll-behavior: smooth; }
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details[open] .faq-icon { transform: rotate(45deg); }
    </style>
</head>
<body class="bg-surface font-body text-ink antialiased">
    {!! Theme::content() !!}
</body>
</html>
