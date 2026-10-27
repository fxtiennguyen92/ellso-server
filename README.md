# Local

PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8000 --no-reload

http://localhost:8000/admin

`PHP_CLI_SERVER_WORKERS=4` cho server dev xử lý song song các file CSS/JS/font của CMS
(mặc định chỉ 1 luồng, tải lần lượt từng file nên mở trang chậm). Có thể đặt luôn trong `.env`.

# Production (AWS)

| Thành phần | Dịch vụ |
|---|---|
| CMS + API (Laravel, PHP 8.4) | Elastic Beanstalk, Amazon Linux 2023, single instance |
| HTTPS, domain `cms.` / `api.` | CloudFront → Elastic Beanstalk |
| Database | RDS MySQL 8.4 |
| Ảnh công khai | S3 `media/` + CloudFront `media.` |
| Tài liệu (video HLS, PDF, sách) | S3 `documents/` (link tạm có chữ ký) |
| Mail | Gmail SMTP |

Local và production chỉ khác nhau ở biến môi trường (`MEDIA_DISK`, `DOCUMENTS_DISK`, `DB_*`, `AWS_*`…),
danh sách đầy đủ: `deploy/aws/eb-environment-properties.txt`.

**Deploy:** merge vào `main` → GitHub Actions (`.github/workflows/deploy.yml`) chạy test trên MySQL,
build `vendor/`, đẩy lên Elastic Beanstalk. Nhánh khác chỉ chạy test.

Máy chủ tự làm sau mỗi lần deploy (`.platform/`):
- cài ffmpeg (máy mới), migrate, `optimize` + `filament:optimize`, khởi động lại queue worker (systemd `ellso-queue`);
- đổi biến môi trường trong console → cache lại config + khởi động lại worker.

Chạy lệnh artisan trên máy chủ (EC2 › Connect › Session Manager):

    sudo bash /var/app/current/.platform/scripts/artisan.sh about
