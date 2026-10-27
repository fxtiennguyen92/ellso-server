{{--
    Đăng nhập thành công → Filament chuyển trang bằng window.location, trong lúc chờ trang quản trị tải
    màn hình đăng nhập đứng yên. Lớp phủ này báo cho người dùng biết đã đăng nhập và đang mở trang.
--}}
<div
    id="ellso-login-overlay"
    role="status"
    aria-live="polite"
    hidden
    style="position: fixed; inset: 0; z-index: 60; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.92); backdrop-filter: blur(2px);"
>
    <div style="display: flex; flex-direction: column; align-items: center; gap: 16px; text-align: center; padding: 24px;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="animation: ellso-spin 0.9s linear infinite; color: var(--primary-600, #047857);">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.2" stroke-width="3"></circle>
            <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
        </svg>
        <div>
            <p style="margin: 0; font-size: 16px; line-height: 24px; font-weight: 600; color: #0f172a;">Đăng nhập thành công</p>
            <p style="margin: 4px 0 0; font-size: 14px; line-height: 20px; color: #475569;">Đang mở trang quản trị…</p>
        </div>
    </div>
</div>

<style>
    @keyframes ellso-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { #ellso-login-overlay svg { animation-duration: 2.5s; } }
    .dark #ellso-login-overlay { background: rgba(9, 9, 11, 0.9) !important; }
    .dark #ellso-login-overlay p:first-child { color: #f4f4f5 !important; }
    .dark #ellso-login-overlay p:last-child { color: #a1a1aa !important; }
</style>

<script>
    (() => {
        const overlay = () => document.getElementById('ellso-login-overlay');

        const register = () => {
            // Phản hồi của Livewire có lệnh chuyển trang = đăng nhập thành công (sai mật khẩu thì không chuyển)
            window.Livewire.hook('commit', ({ succeed }) => {
                succeed(({ effects }) => {
                    if (effects && effects.redirect && overlay()) {
                        overlay().hidden = false;
                    }
                });
            });
        };

        if (window.Livewire) {
            register();
        } else {
            document.addEventListener('livewire:init', register, { once: true });
        }

        // Bấm Back quay lại trang đăng nhập (bfcache): ẩn lớp phủ
        window.addEventListener('pageshow', (event) => {
            if (event.persisted && overlay()) {
                overlay().hidden = true;
            }
        });
    })();
</script>
