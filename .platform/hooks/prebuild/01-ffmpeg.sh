#!/bin/bash
# Cài ffmpeg + ffprobe (bản static) cho job cắt video HLS — Amazon Linux 2023 không có sẵn gói ffmpeg.
# Chỉ tải khi máy chưa có (máy mới / máy được thay thế). Chạy với quyền root, trước khi deploy code.
set -euo pipefail

if [ -x /usr/local/bin/ffmpeg ] && [ -x /usr/local/bin/ffprobe ]; then
    exit 0
fi

case "$(uname -m)" in
    x86_64) arch=amd64 ;;
    aarch64) arch=arm64 ;;
    *) echo "ffmpeg: không hỗ trợ kiến trúc $(uname -m)" >&2; exit 1 ;;
esac

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

curl -fsSL --retry 3 -o "$work/ffmpeg.tar.xz" "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-${arch}-static.tar.xz"
tar -xJf "$work/ffmpeg.tar.xz" -C "$work"
install -m 0755 "$work"/ffmpeg-*-static/ffmpeg /usr/local/bin/ffmpeg
install -m 0755 "$work"/ffmpeg-*-static/ffprobe /usr/local/bin/ffprobe

/usr/local/bin/ffmpeg -hide_banner -version | head -1
