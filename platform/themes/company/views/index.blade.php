@php
    Theme::layout('landing');
    Theme::set('landingTitle', 'Selectum Noa · Xếp lịch nhà hàng');
    Theme::set('landingDescription', 'Hệ thống xếp ca nội bộ cho bộ phận F&B – Selectum Noa Resort Cam Ranh.');

    // Chưa đăng nhập: /admin/roster chuyển sang trang đăng nhập rồi quay lại bảng xếp ca.
    $rosterUrl = route('roster.index');
    $screenshot = asset('themes/company/assets/phanmemxeplich/images/xeplich/xep-ca.png');

    $features = [
        ['view_week', 'Bảng xếp ca theo tuần', 'Xếp ca cho từng nhân viên theo mẫu ca M6, M9, OH, A2, N; sao chép tuần trước và công bố lịch.'],
        ['warning', 'Kiểm tra xung đột tự động', 'Cảnh báo nghỉ chưa đủ 12h giữa 2 ca, làm quá 12h/ngày, liên tục quá 6 ngày, tăng ca vượt mức.'],
        ['storefront', 'Theo từng outlet', 'Lịch riêng cho Nhà hàng Gusto Buffet và Nhà hàng Noa Villa.'],
    ];
@endphp

<div class="min-h-screen flex flex-col">
    <header class="bg-navy border-b border-white/10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-lg bg-gold text-navy-deep grid place-items-center font-display font-bold">S</span>
                <div class="leading-tight">
                    <div class="font-display font-semibold text-white">Selectum Noa</div>
                    <div class="font-mono text-[10px] tracking-wider uppercase text-gold">F&B Operations</div>
                </div>
            </div>
            <a href="{{ $rosterUrl }}" class="text-sm text-white/75 hover:text-white inline-flex items-center gap-1">
                <span class="material-symbols-outlined">login</span> Đăng nhập
            </a>
        </div>
    </header>

    <main class="flex-1">
        <section class="relative overflow-hidden bg-navy text-white">
            <div class="absolute inset-0 opacity-[.07]" style="background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px); background-size: 48px 48px;"></div>
            <div class="relative max-w-6xl mx-auto px-4 sm:px-6 pt-16 md:pt-20 pb-10 text-center">
                <span class="inline-flex items-center gap-2 rounded-full border border-gold/40 bg-gold/10 px-3 py-1 font-mono text-[11px] tracking-wider text-gold uppercase">
                    Selectum Noa Resort · Cam Ranh
                </span>
                <h1 class="mt-6 font-display font-semibold text-4xl sm:text-5xl tracking-tight leading-[1.15]">
                    Hệ thống xếp ca <span class="text-gold">bộ phận nhà hàng</span>
                </h1>
                <p class="mt-5 text-base md:text-lg text-white/70 max-w-2xl mx-auto leading-relaxed">
                    Công cụ nội bộ giúp quản lý F&B lên lịch làm việc theo tuần cho nhân viên các outlet, kiểm tra giờ nghỉ và tăng ca trước khi công bố.
                </p>
                <a href="{{ $rosterUrl }}" class="mt-9 inline-flex items-center justify-center gap-2 rounded-md bg-gold hover:bg-[#eab983] text-navy-deep text-base font-semibold px-7 py-3.5 transition">
                    <span class="material-symbols-outlined">calendar_month</span> Xếp lịch nhà hàng
                </a>
                <p class="mt-3 text-xs text-white/50">Dành cho quản lý đã được cấp tài khoản.</p>
            </div>
            <div class="relative max-w-5xl mx-auto px-4 sm:px-6 -mb-px">
                <div class="rounded-t-xl border border-white/15 border-b-0 bg-white/5 p-2 pb-0 shadow-frame">
                    <div class="rounded-t-lg overflow-hidden bg-white h-[200px] sm:h-[320px] md:h-[400px]">
                        <img src="{{ $screenshot }}" alt="Bảng xếp ca theo tuần" class="w-full h-full object-cover object-top">
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white border-b border-line">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12 grid md:grid-cols-3 gap-8">
                @foreach ($features as [$icon, $title, $text])
                    <div class="flex gap-4">
                        <span class="w-11 h-11 shrink-0 rounded-lg bg-navy text-gold grid place-items-center"><span class="material-symbols-outlined">{{ $icon }}</span></span>
                        <div>
                            <h2 class="font-display font-semibold text-lg">{{ $title }}</h2>
                            <p class="mt-1 text-sm text-ink-muted leading-relaxed">{{ $text }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </main>

    <footer class="bg-navy-deep text-white/60 text-sm">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>© {{ date('Y') }} Selectum Noa Resort Cam Ranh · F&B Operations</p>
            <a href="{{ $rosterUrl }}" class="hover:text-white">Vào hệ thống xếp ca →</a>
        </div>
    </footer>
</div>
