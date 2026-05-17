# Throttle
<img width="1901" height="908" alt="image" src="https://github.com/user-attachments/assets/b8676856-ba23-4291-85b7-4d3fa64f8094" />

More screenshots: [docs/screenshots](docs/screenshots/)

## English

### What This Is

Throttle is a Symfony/Silex crash-reporting service for Source engine servers using SourceMod Accelerator. It accepts Breakpad minidumps, stores and processes crash reports, accepts Breakpad symbol files, can generate symbols from uploaded binaries, and provides a web dashboard for owners and admins.

This 2026 branch includes:

- Steam-only user login.
- Public Light/Dark/System theme switcher.
- Profile upload tokens and token usage/audit statistics.
- SourceMod Accelerator `core.cfg` generator.
- `/submit` crash uploads.
- `/symbols/submit` symbol uploads with token checks.
- `/binary/submit` binary uploads with token checks and symbol generation.
- Crash processing logs per report.
- Symbol coverage view for report owners/admins.
- Admin tools for manual binary uploads, symbol cache refresh, and stored symbol export.
- Likely crash cause scoring.
- SourceMod plugin/extension snapshots from Accelerator metadata when available.
- Admin health page and systemd timer files for processing.

### Requirements

- PHP 8.4 CLI and PHP-FPM.
- MariaDB 10.11 or newer.
- Redis.
- Composer.
- Node.js and npm for asset builds.
- Nginx and PHP-FPM for manual VPS installs.
- Docker Compose for Docker installs.

Required PHP extensions include `ctype`, `iconv`, `intl`, `pdo_mysql`, `bcmath`, `xsl`, `zip`, and common Symfony runtime extensions. Do not enable duplicate PHP extensions in `php.ini`; install them through packages and let PHP load the matching `conf.d` files.

### Docker Compose Install

Start from a clean clone:

```bash
git clone https://github.com/MrPanica/throttle throttle
cd throttle
cp .env.prod.docker.example .env.prod.docker
```

Edit `.env.prod.docker` and set at least:

```dotenv
APP_SECRET=change-this-to-a-long-random-value
MARIADB_PASSWORD=change-this
MARIADB_ROOT_PASSWORD=change-this-too
STEAM_API_KEY=optional-steam-web-api-key
APP_ADMINS=steam:YOUR_STEAMID64
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
APP_PORT=18080
AUTO_MIGRATE=1
```

Run:

```bash
docker compose --env-file .env.prod.docker -f compose.prod.yaml up -d --build
docker compose --env-file .env.prod.docker -f compose.prod.yaml ps
docker compose --env-file .env.prod.docker -f compose.prod.yaml exec app php bin/console doctrine:migrations:status --env=prod
```

The production compose file starts the web app plus a `processor` service that runs `crash:process --update --limit=10` every minute. Persistent Docker volumes keep the database, Redis data, crash dumps, symbol files, and runtime `var/` data, including `/health` symbol request policy and upload processing settings.

Open `http://SERVER_IP:18080/` or put Nginx/Traefik/Caddy in front of the container. After first Steam login, use `APP_ADMINS` to grant access to `/health`.

### Manual Ubuntu/Nginx/PHP-FPM Install

Install PHP 8.4, MariaDB, Redis, Nginx, Composer, Node.js, and npm. Then deploy the source.

On Ubuntu 24.04, the stock archive does not provide PHP 8.4. Add a PHP 8.4 package source first, for example `ppa:ondrej/php`, or use an OS/repository that already ships PHP 8.4.

```bash
cd /var/www/throttle
APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader
npm ci
npm run build
```

Create `.env.local` on the server only:

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
```

Create the database and user:

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

Build the production environment and run migrations:

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

Nginx must point the virtual host root at `public/`, pass PHP to PHP 8.4 FPM, and allow large uploads:

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

### SourceMod Accelerator core.cfg

Login with Steam, open **Profile**, generate a token, adjust the `core.cfg` generator, click **Generate settings**, then paste the generated block at the bottom of:

```text
addons/sourcemod/configs/core.cfg
```

Example shape:

```text
"MinidumpAccount" "YOUR_STEAMID64"

"MinidumpSymbolUpload" "3"
"MinidumpBinaryUpload" "yes"
"MinidumpPresubmit" "yes"

"MinidumpUrl" "https://crash.example.com/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpSymbolUrl" "https://crash.example.com/symbols/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpBinaryUrl" "https://crash.example.com/binary/submit?token=YOUR_PROFILE_TOKEN"
```

If the server's libcurl does not support HTTPS, use HTTP behind your own trusted network/reverse proxy setup, or update the server runtime so Accelerator can upload over HTTPS.

### Upload Token Behavior

- `/submit` accepts crash dumps without a token by default so legacy Accelerator crash uploads still work.
- Admins can disable anonymous minidump uploads in `/health`; when disabled, `/submit` requires either a profile upload token or the global `SYMBOL_UPLOAD_TOKEN`.
- `/submit?token=PROFILE_TOKEN` records profile token usage for crash uploads.
- `/symbols/submit` requires a profile token, an admin session, or the global `SYMBOL_UPLOAD_TOKEN`.
- `/binary/submit` requires a profile token, an admin session, or the global `SYMBOL_UPLOAD_TOKEN`.
- Rejected symbol/binary uploads are written to the token activity audit when possible.
- Never publish upload tokens in public docs, plugin source, screenshots, or commits.

### Crash Processing

Docker Compose deployments already run the `processor` service. For manual VPS deployments, install the provided systemd files:

```bash
cp deploy/systemd/throttle-crash-process.service /etc/systemd/system/
cp deploy/systemd/throttle-crash-process.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now throttle-crash-process.timer
systemctl list-timers throttle-crash-process.timer
```

Run one processor pass manually:

```bash
sudo -u www-data php8.4 /var/www/throttle/bin/console crash:process --env=prod --no-debug --update --limit=10
```

In a crash report, **Processing Runs** are Throttle processor records: status, duration, summary, and processing steps. **Stackwalk stderr** is the raw `minidump_stackwalk` stderr stream with symbol lookup, module parsing, warnings, and stackwalker errors. They can look similar because the processor stores stackwalker output inside the run log for debugging.

### Health and Troubleshooting

- The Light/Dark/System theme switcher is available before login and stores `light`, `dark`, or `system` in browser local storage. The default is `system`.
- `/health` is admin-only and shows queue, storage, binary, and runtime health.
- `/health` upload processing settings now include:
  - streaming `.sym` upload processing
  - upload endpoint memory limit
  - anonymous minidump upload toggle for `/submit`
  - upload failure backoff for repeated symbol/binary upload failures on the same `module + identifier`
- `/health` binary upload request policy controls which Linux modules Accelerator is asked to upload during presubmit. Defaults are intentionally empty, so a fresh install will not request symbol or binary uploads until allow rules are configured.
- `/health` also includes admin tools to:
  - refresh symbol caches and rescan stored symbols
  - clear upload failure backoff state
  - upload binaries manually and generate symbols from them
  - browse and export stored `.sym.gz` files
- Saved health runtime data is stored in:
  - `var/symbol-request-policy.json`
  - `var/upload-settings.json`
  - `var/upload-failure-backoff.json`
- Grant admin access without editing the database by setting `APP_ADMINS` in `.env.local` or the service environment. Accepted values are separated by comma, space, or semicolon: numeric local user ids, `user:<id>`, SteamID64 values, or `steam:<SteamID64>`, for example `APP_ADMINS="steam:STEAMID64,user:1"`.
- Admins can access `/health`, see global dashboards/audit data, view and manage crash reports they do not own, reprocess/delete crashes, and delete any crash signature note.
- Pending crashes usually mean `crash:process` is not running or failed.
- `bin/carburetor`, `bin/minidump_stackwalk`, `bin/dump_syms`, `bin/breakpad_moduleid`, and `bin/nm` must be executable.
- `var/`, `cache/`, `dumps/`, and `symbols/` must be writable by the PHP-FPM user. Back up `var/symbol-request-policy.json`, `var/upload-settings.json`, and `var/upload-failure-backoff.json` if you use custom `/health` rules.
- If templates fail with missing Encore entrypoints, run `npm ci && npm run build` and verify `public/build/entrypoints.json` exists.
- If Composer uses PHP 8.1 on a PHP 8.4 project, run Composer through PHP 8.4 and force the production environment during install: `APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader`.
- If PHP-FPM logs duplicate or missing extensions, fix `/etc/php/8.4/fpm/php.ini` and use package-managed `conf.d` extension files.

### Security Notes

- Never commit `.env.local`, `.env.local.php`, real tokens, database passwords, crash dumps, symbols, binaries, or production logs.
- Keep `APP_SECRET`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN`, and OAuth secrets in server environment files only.
- Put the app behind HTTPS in production.
- Keep PHP, Composer dependencies, Node dependencies, Breakpad tools, and SourceMod Accelerator updated.

## Русский

### Что Это

Throttle - сервис на Symfony/Silex для приема и анализа crash-report'ов Source engine серверов через SourceMod Accelerator. Он принимает Breakpad minidump'ы, хранит и обрабатывает краши, принимает symbol files, может генерировать symbols из загруженных бинарников и показывает веб-панель для владельцев серверов и администраторов.

Ветка 2026 включает:

- Вход только через Steam.
- Upload token в профиле и статистику/аудит его использования.
- Генератор настроек SourceMod Accelerator `core.cfg`.
- Прием crash dump'ов через `/submit`.
- Прием symbols через `/symbols/submit` с проверкой токена.
- Прием binaries через `/binary/submit` с проверкой токена и генерацией symbols.
- Лог обработки для каждого краша.
- Symbol coverage для владельца отчета и администраторов.
- Оценку вероятной причины краша.
- Snapshot списка SourceMod plugins/extensions из metadata, если Accelerator его передал.
- Админскую страницу `/health` и systemd timer для обработки очереди.

### Требования

- PHP 8.4 CLI и PHP-FPM.
- MariaDB 10.11 или новее.
- Redis.
- Composer.
- Node.js и npm для сборки assets.
- Nginx и PHP-FPM для ручной установки на VPS.
- Docker Compose для Docker установки.

Нужны PHP extensions `ctype`, `iconv`, `intl`, `pdo_mysql` и стандартные расширения Symfony runtime. Не подключайте одни и те же extensions дважды в `php.ini`; ставьте их пакетами и используйте соответствующие файлы `conf.d`.

### Установка Через Docker Compose

Начните с чистого клона:

```bash
git clone https://github.com/MrPanica/throttle throttle
cd throttle
cp .env.prod.docker.example .env.prod.docker
```

Отредактируйте `.env.prod.docker` и задайте минимум:

```dotenv
APP_SECRET=change-this-to-a-long-random-value
MARIADB_PASSWORD=change-this
MARIADB_ROOT_PASSWORD=change-this-too
STEAM_API_KEY=optional-steam-web-api-key
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
APP_PORT=18080
AUTO_MIGRATE=1
```

Запуск:

```bash
docker compose --env-file .env.prod.docker -f compose.prod.yaml up -d --build
docker compose --env-file .env.prod.docker -f compose.prod.yaml ps
docker compose --env-file .env.prod.docker -f compose.prod.yaml exec app php bin/console doctrine:migrations:status --env=prod
```

Откройте `http://SERVER_IP:18080/` или поставьте перед контейнером Nginx/Traefik/Caddy.

### Ручная Установка Ubuntu/Nginx/PHP-FPM

Установите PHP 8.4, MariaDB, Redis, Nginx, Composer, Node.js и npm. Затем разверните исходники:

```bash
cd /var/www/throttle
APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader
npm ci
npm run build
```

Создайте `.env.local` только на сервере:

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
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
```

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

Соберите production окружение и выполните миграции:

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

Nginx должен смотреть в `public/`, передавать PHP в PHP 8.4 FPM и разрешать большие upload'ы:

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

### SourceMod Accelerator core.cfg

Войдите через Steam, откройте **Profile**, сгенерируйте token, настройте генератор `core.cfg`, нажмите **Generate settings** и вставьте получившийся блок в самый низ файла:

```text
addons/sourcemod/configs/core.cfg
```

Пример структуры:

```text
"MinidumpAccount" "YOUR_STEAMID64"

"MinidumpSymbolUpload" "3"
"MinidumpBinaryUpload" "yes"
"MinidumpPresubmit" "yes"

"MinidumpUrl" "https://crash.example.com/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpSymbolUrl" "https://crash.example.com/symbols/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpBinaryUrl" "https://crash.example.com/binary/submit?token=YOUR_PROFILE_TOKEN"
```

Если libcurl на игровом сервере не поддерживает HTTPS, используйте HTTP только за доверенным reverse proxy/внутренней сетью или обновите runtime сервера, чтобы Accelerator мог отправлять через HTTPS.

### Поведение Upload Token

- `/submit` принимает crash dumps без token, чтобы старые загрузки Accelerator продолжали работать.
- `/submit?token=PROFILE_TOKEN` записывает использование profile token для crash upload'ов.
- `/symbols/submit` требует profile token, admin session или глобальный `SYMBOL_UPLOAD_TOKEN`.
- `/binary/submit` требует profile token, admin session или глобальный `SYMBOL_UPLOAD_TOKEN`.
- Отклоненные symbol/binary upload'ы записываются в token activity audit, когда это возможно.
- Не публикуйте upload tokens в публичной документации, исходниках плагинов, скриншотах или git commit'ах.

### Обработка Крашей

Для ручной VPS установки подключите systemd файлы:

```bash
cp deploy/systemd/throttle-crash-process.service /etc/systemd/system/
cp deploy/systemd/throttle-crash-process.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now throttle-crash-process.timer
systemctl list-timers throttle-crash-process.timer
```

Один ручной запуск processor'а:

```bash
sudo -u www-data php8.4 /var/www/throttle/bin/console crash:process --env=prod --no-debug --update --limit=10
```

### Health И Диагностика

- `/health` доступен только администраторам и показывает checks, очередь, каталоги runtime, бинарники, состояние symbol storage и runtime-настройки.
- Админов можно задавать через `APP_ADMINS`: поддерживаются `user:<id>`, SteamID64 и `steam:<SteamID64>`, разделители `,`, пробел и `;`.
- В `Upload processing settings` можно:
  - включать или отключать анонимные `/submit` uploads;
  - включать потоковую обработку `.sym`;
  - задавать отдельный `memory_limit` только для `/symbols/submit` и `/binary/submit`;
  - включать upload failure backoff, чтобы Throttle не просил один и тот же missing module бесконечно после повторяющихся 4xx/5xx ошибок upload endpoints;
  - настраивать threshold и TTL для этого backoff.
- `Binary upload request policy` по умолчанию пустой. После fresh install Throttle не будет автоматически запрашивать symbols или binaries, пока вы явно не зададите allow-правила.
- Через `/health` также можно:
  - обновить symbol cache и пересчитать `present` после ручной загрузки symbols;
  - очистить upload-failure backoff state;
  - загрузить бинарник и автоматически сгенерировать `.sym.gz` через `dump_syms` или деградированный fallback через `nm`;
  - искать сохранённые symbols и экспортировать выбранные `.sym.gz` в ZIP.
- Runtime-файлы `/health`, которые стоит включать в бэкапы:
  - `var/symbol-request-policy.json`
  - `var/upload-settings.json`
  - `var/upload-failure-backoff.json`
- Если краши долго pending, значит `crash:process` не запущен или падает.
- `bin/carburetor`, `bin/minidump_stackwalk`, `bin/dump_syms`, `bin/breakpad_moduleid` и `bin/nm` должны быть executable.
- `var/`, `cache/`, `dumps/` и `symbols/` должны быть writable для пользователя PHP-FPM.
- Если ошибка говорит про missing Encore entrypoints, выполните `npm ci && npm run build` и проверьте `public/build/entrypoints.json`.
- Если Composer запускается через PHP 8.1 в проекте PHP 8.4, используйте `APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader`.
- Если PHP-FPM пишет про duplicate или missing extensions, исправьте `/etc/php/8.4/fpm/php.ini` и используйте package-managed `conf.d` файлы.

### Заметки По Безопасности

- Никогда не коммитьте `.env.local`, `.env.local.php`, реальные tokens, database passwords, crash dumps, symbols, binaries или production logs.
- `APP_SECRET`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN` и OAuth secrets должны быть только в environment/server files.
- В production используйте HTTPS.
- Обновляйте PHP, Composer dependencies, Node dependencies, Breakpad tools и SourceMod Accelerator.

