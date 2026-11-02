# Deploy ellso-server lên BizFly Cloud

Hướng dẫn đầy đủ từng bước (mua dịch vụ, cài server, đổi DNS): doc **“Ellso – Chuyển ellso-server từ AWS sang BizFly Cloud”** trong Project Ellso.

## Hạ tầng (HCM)

| Thành phần | Giá trị |
| --- | --- |
| Cloud Server `ellso-server-prod` | Ubuntu 24.04, IP công khai `103.153.75.83`, IP nội bộ `10.20.1.205` |
| Cloud Database `ellso-db` | MySQL, IP nội bộ `10.20.1.19`, database `ellso`, user `ellso_app` |
| Simple Storage | `ellso-media` (Public), `ellso-documents` (Private), endpoint `https://hcm.ss.bfcplatform.vn` |
| Web | nginx + PHP 8.4-FPM (user `deploy`), HTTPS Let's Encrypt (certbot tự gia hạn) |
| Queue worker | systemd `ellso-queue` (user `deploy`) |

## Thư mục trên server

```
/var/www/ellso/releases/<YYYYmmdd-HHMMSS-commit>   mỗi lần deploy một bản (giữ 5 bản mới nhất)
/var/www/ellso/current -> releases/<bản đang chạy>
/var/www/ellso/shared/.env                         biến môi trường (mẫu: env.example)
/var/www/ellso/shared/storage                      log, cache, file tạm của Laravel
```

## Deploy

Push / merge vào `main` → GitHub Actions (`.github/workflows/deploy.yml`, job `deploy-bizfly`):
test → `composer install --no-dev` → đẩy code (tar qua SSH, user `deploy`) vào `releases/` → `activate.sh`
(nối `.env` + `storage`, `migrate`, `optimize`, `filament:optimize`, đổi `current`, reload PHP-FPM,
restart queue, kiểm tra `/up`).

Cần trong GitHub › Settings › Secrets and variables › Actions:

| Loại | Tên | Giá trị |
| --- | --- | --- |
| Secret | `BIZFLY_SSH_KEY` | khoá bí mật của user `deploy` |
| Variable | `BIZFLY_HOST` | `103.153.75.83` |
| Variable | `BIZFLY_USER` | `deploy` |

Job chỉ chạy khi có `BIZFLY_HOST`. Job Elastic Beanstalk cũ chỉ chạy khi còn `EB_ENVIRONMENT` → xoá biến đó khi tắt AWS.

## Thao tác thường dùng (SSH bằng root)

```bash
bash /var/www/ellso/current/deploy/bizfly/artisan.sh about             # chạy artisan
sudo -u deploy bash /var/www/ellso/current/deploy/bizfly/rollback.sh   # quay lại bản trước (không lùi migration)
tail -n 50 /var/www/ellso/shared/storage/logs/laravel-$(date +%F).log  # log Laravel
journalctl -u ellso-queue -n 50                                        # log queue worker
```

Sau khi sửa `/var/www/ellso/shared/.env`: `artisan.sh optimize`, rồi `systemctl reload php8.4-fpm && systemctl restart ellso-queue`.

## Lưu ý

- Tránh deploy khi có video đang xử lý (worker bị khởi động lại).
- `migrate` chạy trước khi đổi `current`: migration lỗi → web vẫn chạy bản cũ.
- Storage BizFly chỉ có quyền Public / Private cho cả bucket → ảnh và tài liệu ở 2 bucket riêng
  (`AWS_MEDIA_BUCKET`, `MEDIA_S3_ACL=public-read`; `AWS_BUCKET`, `DOCUMENTS_S3_ACL=private`). CORS và rule
  xoá `documents/livewire-tmp/` sau 1 ngày đã cài bằng AWS CLI (doc, Bước 5.7).
