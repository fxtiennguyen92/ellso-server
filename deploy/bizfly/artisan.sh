#!/bin/bash
# Chạy lệnh artisan trên server BizFly (đăng nhập root qua SSH), dưới user deploy:
#   bash /var/www/ellso/current/deploy/bizfly/artisan.sh about
#   ADMIN_EMAIL=ban@ellso.edu.vn ADMIN_PASSWORD='MatKhauManh' bash /var/www/ellso/current/deploy/bizfly/artisan.sh db:seed --force
# Sau khi sửa /var/www/ellso/shared/.env: chạy "artisan.sh optimize" rồi
#   systemctl reload php8.4-fpm && systemctl restart ellso-queue
cd /var/www/ellso/current
exec sudo -E -u deploy php artisan "$@"
