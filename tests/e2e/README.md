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

# HTTP-проверки 1.0.22: возврат к прерванной попытке и автосохранение (164 проверки)
python3 /home/user/wordprees/tests/e2e/e2e-resume-draft.py \
    --wp /home/user/.cache/wpo-test/wordpress \
    --cli /home/user/.cache/wpo-test/wp-cli.phar \
    --base http://localhost:8127
```

## Запуск негативного и стресс-прогона

```bash
cd "$WP"   # документы WordPress со стенда
python3 "$REPO/tests/e2e/fuzz-1022.py" --base http://localhost:8127
```

Прогон сверяет `wp-content/debug.log`, поэтому на стенде должны быть включены
`WP_DEBUG` и `WP_DEBUG_LOG` (в `wp-config.php`): иначе проверка «лог пуст»
проходит впустую. Драйвер читает конфигурацию WordPress, печатает проверку
`V0. На стенде включён WP_DEBUG_LOG (иначе проверка лога пуста)` и в файле лога
разбирается по значению `WP_DEBUG_LOG`, а не по угаданному пути.

## Файлы

| Файл | Что делает |
| --- | --- |
| `make-quiz.php` | создаёт олимпиаду с анкетой, двумя группами (у одной — лимит 1 минута), постраничным выводом и страницей с шорткодом; печатает JSON с id; старые фикстуры этой проверки удаляются |
| `dump-attempt.php <token>` | дамп попытки: статус и метка, баллы, `participant_meta` (анкета, отметки групп, порядок), `draft_answers`, `draft_updated_at`, ответы с пометками ручной проверки |
| `shift-attempt.php <token> <сек> [--groups]` | сдвигает `started_at` (и при `--groups` — отметки открытия групп) в прошлое, чтобы проверить лимиты без ожидания |
| `set-group-stamp.php <token> <группа> <сек\|clear>` | ставит отметку открытия группы в прошлое или снимает её |
| `set-quiz-status.php <quiz_id> <publish\|draft>` | публикует/снимает олимпиаду и чистит кеш `Quiz::clear_cache()` |
| `protocol-csv.php <quiz_id>` | печатает `протокол_<имя>.csv` тем же кодом, что отдаёт кнопку выгрузки |
| `fuzz-1022.py` | негативный и стресс-прогон 1.0.22/1.0.23: мусор и неверные типы во всех точках, границы потолков черновика, чужие токены, AJAX-мост, 150 автосейвов и гонки, инварианты БД, поиск утечек и пустоты `debug.log` |
| `dom-start-check.mjs` | DOM-прогон кнопки «Начать выполнение олимпиады» (jsdom + живой REST): `start`, `reload-continue`, `reload-restart`, `reload-dismiss`, `slow-start`, `resume-no-questions`; требует `npm i jsdom` |
| `e2e-resume-draft.py` | сам прогон: фикстура → HTTP → БД → протокол → админка → грепы по установленной копии; в конце печатает «Проверок: N, пройдено: N, отказов: 0» |

## Прогон кнопки в DOM (`dom-start-check.mjs`)

Серверные проверки не выполняют JS раннера: в 1.0.23 они были зелёные, а клик по
«Начать выполнение олимпиады» — мёртвый. `dom-start-check.mjs` грузит страницу
стенда в jsdom, подставляет живой REST стенда вместо `fetch`, исполняет
настоящий `public/js/olympiad-frontend.js` и проверяет последствие нажатия:
появились ли карточки `.wpo-question-card-inner`, скрыто ли интро, не остался ли
оверлей, разблокирована ли кнопка, и не появилось ли непойманных
`unhandledrejection`/`window.onerror`.

```bash
cd /home/user/.cache/dom1024 && npm i jsdom@24     # один раз, каталог вне репозитория
ln -sfn /home/user/.cache/dom1024/node_modules /home/user/wordprees/node_modules   # ESM-резолвинг
WP=/home/user/.cache/wpo-test/wordpress
cd "$WP" && FIX=$(php ../wp-cli.phar eval-file wp-content/plugins/wp-olympiad/tests/e2e/make-quiz.php --allow-root | tail -1)
PAGE=$(python3 -c "import json,sys; print(json.loads(sys.argv[1])['page_id'])" "$FIX")
node /home/user/wordprees/tests/e2e/dom-start-check.mjs --base http://localhost:8127 --page "$PAGE" --scenario start
```

Состояние «живая попытка + перезагрузка» сценарии `reload-continue`, `reload-restart`
и `reload-dismiss` создают сами: `POST /start` через REST, затем запись о токене
подсовывается в `localStorage` страницы. Коды ответа REST-маршрутов на стенде без
«постоянных ссылок» снимаются через `?rest_route=`, поэтому `--base` достаточно.

Для `resume-no-questions` состояние готовит оператор — попытка должна остаться
живой, а задания олимпиады — исчезнуть:

```bash
php ../wp-cli.phar db export /tmp/questions.sql --tables=wp_olympiad_questions --allow-root
php ../wp-cli.phar db query "DELETE FROM wp_olympiad_questions" --allow-root
TOKEN=$(php ../wp-cli.phar db query "SELECT token FROM wp_olympiad_attempts WHERE status='in_progress' ORDER BY id DESC LIMIT 1" --skip-column-names --allow-root | tr -d '[:space:]')
EMAIL=$(php ../wp-cli.phar db query "SELECT participant_email FROM wp_olympiad_attempts WHERE status='in_progress' ORDER BY id DESC LIMIT 1" --skip-column-names --allow-root | tr -d '[:space:]')
node /home/user/wordprees/tests/e2e/dom-start-check.mjs --base http://localhost:8127 --page "$PAGE" --scenario resume-no-questions --token "$TOKEN" --email "$EMAIL"
mysql -u wpo -pwpo wpo < /tmp/questions.sql      # вернуть задания
```

Ожидание: `OK — тихо не осталось`. Диалог возврата при пустом списке заданий не
показывается вообще, а нажатие «Начать» даёт штатное «В этой олимпиаде пока нет
вопросов». Сценарий `slow-start` вешает `fetch` на `/start` (25-секундный таймаут
ждём реально, прогон занимает ~30 с) и требует, чтобы кнопка вернулась в работу с
сообщением «Сервер не отвечает».
