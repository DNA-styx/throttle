# Production установка

## Docker Compose

```bash
git clone https://github.com/MrPanica/throttle throttle
cd throttle
cp .env.prod.docker.example .env.prod.docker
```

Минимально заполните `.env.prod.docker`:

```dotenv
APP_SECRET=change-this-to-a-long-random-value
MARIADB_PASSWORD=change-this
MARIADB_ROOT_PASSWORD=change-this-too
STEAM_API_KEY=optional-steam-web-api-key
APP_ADMINS=steam:YOUR_STEAMID64
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
AI_SETTINGS_KEY=change-this-to-a-long-random-value
APP_PORT=18080
AUTO_MIGRATE=1
```

Запуск:

```bash
docker compose --env-file .env.prod.docker -f compose.prod.yaml up -d --build
docker compose --env-file .env.prod.docker -f compose.prod.yaml ps
docker compose --env-file .env.prod.docker -f compose.prod.yaml exec app php bin/console doctrine:migrations:status --env=prod
```

`compose.prod.yaml` запускает:

- `app` — веб-приложение
- `processor` — обработчик очереди, который раз в минуту выполняет `crash:process --update --limit=10`
- `db` — MariaDB
- `redis` — Redis

Постоянные Docker volumes хранят базу, Redis, `var/`, `dumps/` и `symbols/`. Это важно: runtime-настройки `/health` сохраняются между перезапусками.

## Ручная установка Ubuntu/Nginx/PHP-FPM

Нужны PHP 8.4 CLI/FPM, MariaDB 10.11+, Redis, Composer, Node.js, npm и Nginx. Требуемые PHP extensions включают `ctype`, `iconv`, `intl`, `pdo_mysql`, `bcmath`, `xsl`, `zip`.

На Ubuntu 24.04 стандартный репозиторий не содержит PHP 8.4. Сначала подключите источник пакетов с PHP 8.4, например `ppa:ondrej/php`, либо используйте ОС/репозиторий, где PHP 8.4 уже есть.

```bash
cd /var/www/throttle
APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader
npm ci
npm run build
```

Создайте `.env.local`:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-this-to-a-long-random-value
DATABASE_URL="mysql://throttle:CHANGE_DB_PASSWORD@127.0.0.1:3306/throttle?serverVersion=mariadb-10.11.2&charset=utf8mb4"
REDIS_URL=redis://127.0.0.1:6379
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAILER_DSN=null://null
MAILER_FROM=throttle@example.com
SENTRY_DSN=
STEAM_API_KEY=
APP_ADMINS=steam:YOUR_STEAMID64
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
AI_SETTINGS_KEY=change-this-to-a-long-random-value
```

`AI_SETTINGS_KEY` обязателен для production, если вы хотите хранить пользовательские AI API keys в зашифрованном виде. Если он не задан, Throttle использует `APP_SECRET` как fallback, но отдельный ключ шифрования безопаснее.

Создайте базу и пользователя:

```bash
mariadb -uroot <<SQL
CREATE DATABASE IF NOT EXISTS throttle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'throttle'@'localhost' IDENTIFIED BY 'CHANGE_DB_PASSWORD';
CREATE USER IF NOT EXISTS 'throttle'@'127.0.0.1' IDENTIFIED BY 'CHANGE_DB_PASSWORD';
ALTER USER 'throttle'@'localhost' IDENTIFIED BY 'CHANGE_DB_PASSWORD';
ALTER USER 'throttle'@'127.0.0.1' IDENTIFIED BY 'CHANGE_DB_PASSWORD';
GRANT ALL PRIVILEGES ON throttle.* TO 'throttle'@'localhost';
GRANT ALL PRIVILEGES ON throttle.* TO 'throttle'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

Подготовьте production-окружение:

```bash
php8.4 $(which composer) dump-env prod
sudo -u www-data php8.4 bin/console doctrine:migrations:migrate --no-interaction --env=prod
sudo -u www-data php8.4 bin/console cache:clear --env=prod --no-debug
sudo -u www-data php8.4 bin/console cache:warmup --env=prod --no-debug
chmod +x bin/carburetor bin/minidump_stackwalk bin/dump_syms bin/breakpad_moduleid bin/nm
chown -R www-data:www-data var cache dumps symbols
chmod -R ug+rwX var cache dumps symbols
systemctl restart php8.4-fpm nginx
```

Nginx должен смотреть в `public/` и разрешать крупные uploads:

```nginx
client_max_body_size 100M;
root /var/www/throttle/public;
location / {
    try_files $uri /index.php$is_args$args;
}
location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
```

## Обработка очереди

Для Docker это делает service `processor`.

Для ручной установки подключите systemd timer:

```bash
cp deploy/systemd/throttle-crash-process.service /etc/systemd/system/
cp deploy/systemd/throttle-crash-process.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now throttle-crash-process.timer
systemctl list-timers throttle-crash-process.timer
```

Ручной запуск одного прохода:

```bash
sudo -u www-data php8.4 /var/www/throttle/bin/console crash:process --env=prod --no-debug --update --limit=10
```

## Health

`/health` доступен только администраторам. Админов можно задать через `APP_ADMINS`, например:

```dotenv
APP_ADMINS="steam:STEAMID64,user:1"
```

Поддерживаются `user:<id>`, SteamID64 и `steam:<SteamID64>`. Разделители: запятая, пробел и `;`.

На `/health` видны checks, очередь, runtime-каталоги, бинарники, Binary upload request policy, upload failure backoff и настройки обработки upload endpoints. Через UI можно:

- включать потоковую обработку `.sym`
- задавать отдельный `memory_limit` для `/symbols/submit` и `/binary/submit`
- включать защиту от бесконечных retry-циклов upload endpoints
- вручную обновлять symbol cache
- очищать backoff state
- загружать бинарники с автогенерацией `.sym.gz`
- экспортировать и удалять сохранённые symbols
- включать `Enable AI crash analysis`

Пока `Enable AI crash analysis` выключен, кнопки `Ask AI` на странице крэша и в raw не показываются.

## AI analysis

Пользовательские AI-конфиги настраиваются в **Profile -> AI analysis**. Сейчас поддерживаются:

- OpenAI
- Anthropic
- Google Gemini
- OpenRouter
- Custom OpenAI-compatible

Пользователь может:

- сохранить несколько AI configs
- выбрать default config и default prompt
- задать provider-specific `Extra request JSON`
- запускать анализ страницы крэша и raw output через `Ask AI`
- смотреть историю AI-анализов конкретного крэша
- делать history entries public/private

AI API keys хранятся на сервере в зашифрованном виде. Поэтому `AI_SETTINGS_KEY` нужно хранить вместе с production env и включать в бэкапы env-конфига.

## Что бэкапить

При файловых бэкапах сохраняйте runtime-файлы `/health`:

- `var/symbol-request-policy.json`
- `var/upload-settings.json`
- `var/upload-failure-backoff.json`

Также бэкапьте:

- базу данных
- `dumps/`
- `symbols/`
- production env-файлы с `APP_SECRET`, `AI_SETTINGS_KEY`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN`

AI history и AI configs хранятся в базе данных, поэтому отдельно в файловой системе их нет.

## Диагностика

- Если крэши долго висят в `pending`, значит `crash:process` не запущен или падает.
- `bin/carburetor`, `bin/minidump_stackwalk`, `bin/dump_syms`, `bin/breakpad_moduleid` и `bin/nm` должны быть executable.
- `var/`, `cache/`, `dumps/` и `symbols/` должны быть writable для пользователя PHP-FPM.
- Если ошибка говорит про missing Encore entrypoints, выполните `npm ci && npm run build` и проверьте `public/build/entrypoints.json`.
- Если Composer запускается через PHP 8.1 в проекте PHP 8.4, используйте `APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader`.
- Если PHP-FPM пишет про duplicate или missing extensions, исправьте `/etc/php/8.4/fpm/php.ini` и используйте package-managed `conf.d`.

## Безопасность

- Никогда не коммитьте `.env.local`, `.env.local.php`, реальные tokens, database passwords, crash dumps, symbols, binaries или production logs.
- Держите `APP_SECRET`, `AI_SETTINGS_KEY`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN` и OAuth secrets только в server-side env files.
- В production используйте HTTPS.
- Регулярно обновляйте PHP, Composer dependencies, Node dependencies, Breakpad tools и SourceMod Accelerator.
