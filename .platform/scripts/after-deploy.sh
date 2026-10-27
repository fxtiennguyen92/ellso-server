#!/bin/bash
# Chạy sau mỗi lần deploy code (hooks/postdeploy) và sau mỗi lần đổi biến môi trường (confighooks/postdeploy).
#  1. migrate database
#  2. cache config / route / view (config đọc từ biến môi trường của Elastic Beanstalk tại thời điểm này)
#  3. bật / khởi động lại queue worker (job cắt video HLS)
# Chạy với quyền root trong /var/app/current. Lỗi ở bước nào → deploy báo thất bại (xem Logs › eb-engine.log / eb-hooks.log).
set -euo pipefail

cd /var/app/current

php artisan migrate --force --no-interaction
php artisan optimize
php artisan filament:optimize

# File cache / log vừa tạo bằng root → trả lại cho user chạy PHP-FPM
chown -R webapp:webapp storage bootstrap/cache

# Queue worker (systemd). --timeout khớp ProcessDocumentVideo::$timeout (3600 giây);
# DB_QUEUE_RETRY_AFTER phải lớn hơn (đặt 3700 trong biến môi trường).
cat > /etc/systemd/system/ellso-queue.service <<'UNIT'
[Unit]
Description=Ellso queue worker (php artisan queue:work)
After=network-online.target

[Service]
User=webapp
Group=webapp
WorkingDirectory=/var/app/current
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --timeout=3600 --max-time=3600
Restart=always
RestartSec=5
# Cắt video tốn CPU: ưu tiên thấp hơn web để CMS / API vẫn nhanh
Nice=10
StandardOutput=append:/var/log/ellso-queue.log
StandardError=append:/var/log/ellso-queue.log

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable ellso-queue >/dev/null 2>&1
# Worker đang chạy code cũ → dừng, chạy lại bằng code mới. Video đang cắt dở bị ngắt: khoảng 1 giờ sau
# (DB_QUEUE_RETRY_AFTER) tài liệu chuyển sang "Lỗi" → tải lại video đó. Nên deploy khi không có video đang xử lý.
systemctl restart ellso-queue
