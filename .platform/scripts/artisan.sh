#!/bin/bash
# Chạy lệnh artisan trên máy chủ Elastic Beanstalk (mở bằng EC2 › Connect › Session Manager), dưới user webapp:
#   sudo bash /var/app/current/.platform/scripts/artisan.sh about
#   sudo ADMIN_EMAIL=ban@ellso.edu.vn ADMIN_PASSWORD='MatKhauManh' bash /var/app/current/.platform/scripts/artisan.sh db:seed --force
# Config đã cache lúc deploy (biến môi trường của EB) nên không cần nạp thêm biến nào.
cd /var/app/current
exec sudo -E -u webapp php artisan "$@"
