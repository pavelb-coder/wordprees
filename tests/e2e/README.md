# Реальные HTTP-проверки на стенде

Скрипты этой папки работают **только на тестовой копии** WordPress: фикстуры создают
олимпиады и страницы, сдвигают время и удаляют собственные попытки. На боевом сайте
их запускать нельзя.

## Что нужно

1. MariaDB/MySQL и PHP 8 (CLI) с расширениями `mysqli`, `mbstring`, `xml`, `curl`, `zip`.
2. Установленный WordPress и `wp-cli.phar` рядом с документным корнем.
3. Плагин, установленный в `wp-content/plugins/wp-olympiad` (можно из архива релиза).
4. Запущенный веб-сервер, например:

```bash
php -S 0.0.0.0:8127 -t /path/to/wordpress
```

## Подготовка стенда с нуля (пример для Debian/Ubuntu)

```bash
sudo apt-get update && sudo apt-get install -y php-cli php-mysql php-mbstring php-xml php-curl php-zip php-gd mariadb-server

BASE=/home/user/.cache/wpo-test; mkdir -p "$BASE"; cd "$BASE"
curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
curl -sSL -o wp.zip https://wordpress.org/latest.zip && unzip -q wp.zip

sudo mkdir -p /run/mysqld && sudo chown -R mysql:mysql /run/mysqld /var/lib/mysql
sudo service mariadb start
sudo mariadb -e "CREATE DATABASE IF NOT EXISTS wpo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER IF NOT EXISTS 'wpo'@'localhost' IDENTIFIED BY 'wpo';
                 GRANT ALL ON wpo.* TO 'wpo'@'localhost'; FLUSH PRIVILEGES;"

cd "$BASE/wordpress"
php "$BASE/wp-cli.phar" config create --dbname=wpo --dbuser=wpo --dbpass=wpo --dbhost=localhost --allow-root
php "$BASE/wp-cli.phar" config set timezone_string "Europe/Moscow" --allow-root
php "$BASE/wp-cli.phar" core install --url="http://localhost:8127" --title="WPO QA" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --allow-root
php "$BASE/wp-cli.phar" option update siteurl "http://localhost:8127" --allow-root
php "$BASE/wp-cli.phar" option update home "http://localhost:8127" --allow-root

rsync -a --delete --exclude .git /home/user/wordprees/ wp-content/plugins/wp-olympiad/
php "$BASE/wp-cli.phar" plugin activate wp-olympiad --allow-root
(php -S 0.0.0.0:8127 -t "$BASE/wordpress" &)
```

Проверьте, что REST отвечает — при «постоянных» (plain) ссылках WordPress работает через
`index.php?rest_route=`; скрипты берут базу REST со страницы участника, поэтому и так, и так верно:

```bash
curl -s -X POST "http://localhost:8127/index.php?rest_route=/wp-olympiad/v1/resume" \
     -H 'Content-Type: application/json' -d '{"quiz_id":1,"token":"нет"}'
# ожидаем: {"success":true,"found":false}
```

## Запуск

```bash
# интеграционный сьют (208 проверок)
cd /home/user/.cache/wpo-test/wordpress
php ../wp-cli.phar eval-file wp-content/plugins/wp-olympiad/tests/test-suite.php --allow-root

# HTTP-проверки 1.0.22: возврат к прерванной попытке и автосохранение (163 проверки)
python3 /home/user/wordprees/tests/e2e/e2e-resume-draft.py \
    --wp /home/user/.cache/wpo-test/wordpress \
    --cli /home/user/.cache/wpo-test/wp-cli.phar \
    --base http://localhost:8127
```

## Файлы

| Файл | Что делает |
| --- | --- |
| `make-quiz.php` | создаёт олимпиаду с анкетой, двумя группами (у одной — лимит 1 минута), постраничным выводом и страницей с шорткодом; печатает JSON с id; старые фикстуры этой проверки удаляются |
| `dump-attempt.php <token>` | дамп попытки: статус и метка, баллы, `participant_meta` (анкета, отметки групп, порядок), `draft_answers`, `draft_updated_at`, ответы с пометками ручной проверки |
| `shift-attempt.php <token> <сек> [--groups]` | сдвигает `started_at` (и при `--groups` — отметки открытия групп) в прошлое, чтобы проверить лимиты без ожидания |
| `set-group-stamp.php <token> <группа> <сек\|clear>` | ставит отметку открытия группы в прошлое или снимает её |
| `set-quiz-status.php <quiz_id> <publish\|draft>` | публикует/снимает олимпиаду и чистит кеш `Quiz::clear_cache()` |
| `protocol-csv.php <quiz_id>` | печатает `протокол_<имя>.csv` тем же кодом, что отдаёт кнопку выгрузки |
| `e2e-resume-draft.py` | сам прогон: фикстура → HTTP → БД → протокол → админка → грепы по установленной копии; в конце печатает «Проверок: N, пройдено: N, отказов: 0» |
