#!/usr/bin/env bash
set -e

# ========================================================
# Скрипт быстрой загрузки плагина WP Olympiad в GitHub
# ========================================================

REPO_DIR="/home/user/wp-olympiad"
cd "$REPO_DIR"

if [ -z "$1" ]; then
    echo "Использование: ./push-to-github.sh <URL_РЕПОЗИТОРИЯ_ИЛИ_ТОКЕН>"
    echo ""
    echo "Примеры:"
    echo "1. Через токен (без ввода пароля):"
    echo "   ./push-to-github.sh https://ghp_ВАШ_ТОКЕН@github.com/ВАШ_ЛОГИН/wp-olympiad.git"
    echo ""
    echo "2. Через стандартный HTTPS:"
    echo "   ./push-to-github.sh https://github.com/ВАШ_ЛОГИН/wp-olympiad.git"
    echo ""
    echo "3. Через SSH:"
    echo "   ./push-to-github.sh git@github.com:ВАШ_ЛОГИН/wp-olympiad.git"
    exit 1
fi

REMOTE_URL="$1"

# Удаляем старый remote origin, если был
git remote remove origin 2>/dev/null || true

# Добавляем указанный origin
git remote add origin "$REMOTE_URL"

# Убеждаемся, что ветка называется main
git branch -M main

echo "Отправка кода в репозиторий: $REMOTE_URL..."
git push -u origin main --force

echo "Успешно! Репозиторий загружен в GitHub на ветку main."
