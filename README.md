# Local

PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=8000 --no-reload

http://localhost:8000/admin

`PHP_CLI_SERVER_WORKERS=4` cho server dev xử lý song song các file CSS/JS/font của CMS
(mặc định chỉ 1 luồng, tải lần lượt từng file nên mở trang chậm). Có thể đặt luôn trong `.env`.

# Production

Sau mỗi lần deploy:

php artisan optimize
php artisan filament:optimize
