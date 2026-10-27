#!/bin/bash
# Sau khi deploy code mới: migrate, cache, khởi động lại queue worker
exec bash /var/app/current/.platform/scripts/after-deploy.sh
