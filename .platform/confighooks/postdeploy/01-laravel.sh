#!/bin/bash
# Sau khi đổi biến môi trường trong console: cache lại config (đọc biến mới), khởi động lại queue worker
exec bash /var/app/current/.platform/scripts/after-deploy.sh
