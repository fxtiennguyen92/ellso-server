#!/bin/bash
# Quay lại bản deploy trước (hoặc một bản chỉ định), chạy dưới user deploy:
#   sudo -u deploy bash /var/www/ellso/current/deploy/bizfly/rollback.sh            # bản liền trước
#   sudo -u deploy bash /var/www/ellso/current/deploy/bizfly/rollback.sh <bản>      # bản cụ thể (ls /var/www/ellso/releases)
# Chỉ đổi code, KHÔNG quay lại migration database.
set -euo pipefail

BASE=/var/www/ellso
CURRENT=$(basename "$(readlink -f "$BASE/current")")

if [ -n "${1:-}" ]; then
    TARGET=$1
else
    TARGET=$(ls -1 "$BASE/releases" | sort -r | awk -v c="$CURRENT" '$0 < c' | head -1)
fi
[ -n "$TARGET" ] && [ -d "$BASE/releases/$TARGET" ] || { echo "Không tìm thấy bản để quay lại" >&2; exit 1; }

cd "$BASE/releases/$TARGET"
php artisan optimize
ln -sfn "$BASE/releases/$TARGET" "$BASE/current.new"
mv -Tf "$BASE/current.new" "$BASE/current"
sudo -n /usr/bin/systemctl reload php8.4-fpm
sudo -n /usr/bin/systemctl restart ellso-queue
echo "Đã quay từ $CURRENT về $TARGET"
