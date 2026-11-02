#!/bin/bash
# Kích hoạt một bản code vừa được GitHub Actions đẩy lên server BizFly (chạy dưới user deploy):
#   bash /var/www/ellso/releases/<bản>/deploy/bizfly/activate.sh <bản>
#  1. nối .env + storage dùng chung vào bản mới
#  2. migrate database, cache config / route / view / Filament
#  3. đổi liên kết current sang bản mới (không gián đoạn), reload PHP-FPM, khởi động lại queue worker
#  4. kiểm tra /up, xoá bớt bản cũ (giữ 5 bản mới nhất theo tên để quay lại bằng rollback.sh)
# Lỗi ở bước 1–2 → current vẫn trỏ bản cũ, web không bị ảnh hưởng.
set -euo pipefail

BASE=/var/www/ellso
RELEASE=${1:?Thiếu tên bản (thư mục trong $BASE/releases)}
DIR="$BASE/releases/$RELEASE"
SHARED="$BASE/shared"
KEEP=5

[ -d "$DIR" ] || { echo "Không có $DIR" >&2; exit 1; }
[ -f "$SHARED/.env" ] || { echo "Thiếu $SHARED/.env (Bước 5.6)" >&2; exit 1; }

cd "$DIR"

# Thư mục storage dùng chung (log, cache, session file, file tạm) giữ nguyên qua các lần deploy
mkdir -p "$SHARED"/storage/{app/public,app/private,framework/{cache/data,sessions,views,testing},logs}
ln -sfn "$SHARED/.env" .env
rm -rf storage
ln -sfn "$SHARED/storage" storage
mkdir -p bootstrap/cache

php artisan migrate --force --no-interaction
php artisan optimize
php artisan filament:optimize

# Đổi current trong một bước (mv -T thay liên kết nguyên tử)
ln -sfn "$DIR" "$BASE/current.new"
mv -Tf "$BASE/current.new" "$BASE/current"

# PHP-FPM + OPcache đọc code mới; queue worker chạy lại bằng code mới.
# Video đang cắt dở bị ngắt → khoảng 1 giờ sau (DB_QUEUE_RETRY_AFTER) tài liệu chuyển "Lỗi" → tải lại.
sudo -n /usr/bin/systemctl reload php8.4-fpm
sudo -n /usr/bin/systemctl restart ellso-queue

# Kiểm tra app trả lời (nginx chọn site theo Host, chứng chỉ không cần khớp khi gọi 127.0.0.1)
for i in 1 2 3 4 5; do
    if curl -fsSk -o /dev/null -H 'Host: cms.ellso.edu.vn' https://127.0.0.1/up; then
        echo "OK: $RELEASE đang chạy"
        break
    fi
    if [ "$i" = 5 ]; then
        echo "::error::$RELEASE đã kích hoạt nhưng /up không trả lời. Quay lại bản trước: bash $BASE/current/deploy/bizfly/rollback.sh" >&2
        exit 1
    fi
    sleep 3
done

# Giữ $KEEP bản mới nhất (không bao giờ xoá bản đang chạy)
cd "$BASE/releases"
ls -1 | sort -r | tail -n +$((KEEP + 1)) | while read -r old; do
    [ "$BASE/releases/$old" = "$(readlink -f "$BASE/current")" ] || rm -rf "$BASE/releases/$old"
done
