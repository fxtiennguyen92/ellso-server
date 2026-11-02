# Local

PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8000 --no-reload

http://localhost:8000/admin

`PHP_CLI_SERVER_WORKERS=4` cho server dev xử lý song song các file CSS/JS/font của CMS
(mặc định chỉ 1 luồng, tải lần lượt từng file nên mở trang chậm). Có thể đặt luôn trong `.env`.

# Production (BizFly Cloud, HCM)

| Thành phần | Dịch vụ |
|---|---|
| CMS + API (Laravel, PHP 8.4) | Cloud Server `ellso-server-prod` (Ubuntu 24.04, nginx, IP `103.153.75.83`), domain `cms.` / `api.` |
| HTTPS | Let's Encrypt (certbot tự gia hạn) |
| Database | Cloud Database MySQL `ellso-db` (IP nội bộ `10.20.1.19`) |
| Ảnh công khai | Simple Storage bucket `ellso-media` (Public) |
| Tài liệu (video HLS, PDF, sách) | Simple Storage bucket `ellso-documents` (Private, link tạm có chữ ký) |
| Queue (cắt video HLS) | systemd `ellso-queue` trên cùng server |
| Mail | Gmail SMTP |
| Bản web của app (`app.`) | AWS Amplify (chỉ file tĩnh, gọi API trên BizFly) |

Local và production chỉ khác nhau ở biến môi trường. File `.env` của production nằm trên server ở
`/var/www/ellso/shared/.env` (mẫu: `deploy/bizfly/env.example`). Chi tiết server: `deploy/bizfly/README.md`.

## Cập nhật code lên server

Không cần đăng nhập server: code vào nhánh `main` là tự deploy.

1. Sửa code trên nhánh `claude` (hoặc nhánh khác), push lên GitHub. GitHub Actions tự chạy test.
2. Trên GitHub mở **Pull Request** từ `claude` vào `main`. Chờ dấu kiểm xanh (test qua).
3. Bấm **Merge pull request**.
4. Tab **Actions** › workflow **Deploy** của lần merge: job `test` rồi `deploy-bizfly` chạy (khoảng 3–5 phút).
   Cả hai xanh là xong.
5. Kiểm tra `https://cms.ellso.edu.vn/up` hiện **Application up**, mở CMS / app thử chức năng vừa sửa.

Job `deploy-bizfly` tự làm: build `vendor/` → đẩy code vào thư mục bản mới trên server → `migrate`
→ cache config / route / view → chuyển web sang bản mới (không gián đoạn) → khởi động lại PHP và queue
→ kiểm tra `/up`. Migration lỗi thì web vẫn chạy bản cũ.

**Deploy lại bản đang có ở `main`** (VD sau khi sửa `.env`, hoặc job lỗi do mạng): Actions › **Deploy** ›
**Run workflow** › Branch `main` › **Run workflow**.

**Bản mới bị lỗi → quay lại bản trước** (SSH vào server bằng root, xem `deploy/bizfly/README.md`):

    sudo -u deploy bash /var/www/ellso/current/deploy/bizfly/rollback.sh

Chỉ quay code, không quay database. Sau đó sửa lỗi và deploy lại như trên.

**Lưu ý**
- Không deploy khi có video đang xử lý trên CMS (queue khởi động lại → video đó chuyển “Lỗi”, phải tải lại).
- Thêm biến môi trường mới: sửa `/var/www/ellso/shared/.env` **trước** khi merge code cần biến đó
  (hoặc sửa xong thì chạy lại workflow như trên).
- Không push thẳng vào `main`; mọi thay đổi đi qua Pull Request để test chạy trước.

## Chạy lệnh artisan trên server

SSH vào server (`ssh -i <khoá .pem> root@103.153.75.83`), rồi:

    bash /var/www/ellso/current/deploy/bizfly/artisan.sh about
