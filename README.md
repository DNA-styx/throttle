# Throttle

<img width="1899" height="912" alt="Throttle dashboard screenshot" src="https://github.com/user-attachments/assets/2936ba6f-c91e-4e7c-85cd-6c12ffba94fa" />

More screenshots: [docs/screenshots](docs/screenshots/)

## English

### What This Is

Throttle is a Symfony/Silex crash-reporting service for Source engine servers using SourceMod Accelerator. It accepts Breakpad minidumps, stores and processes crash reports, accepts Breakpad symbol files, can generate symbols from uploaded binaries, and provides a web dashboard for owners and admins. This branch is maintained as a pragmatic vibe-code project, so the codebase is intentionally iterative and operationally focused rather than polished as a generic product.

This 2026 branch includes:

- Steam, Discord, email-link, email + password, and upload-token login
- public Light/Dark/System theme switcher
- profile upload tokens and token usage/audit statistics
- SourceMod Accelerator `core.cfg` generator
- `/submit` crash uploads
- `/symbols/submit` symbol uploads with token checks
- `/binary/submit` binary uploads with token checks and symbol generation
- crash processing logs per report
- symbol coverage views for report owners/admins
- likely crash cause scoring
- optional AI crash analysis with per-user API configs, saved history, and public history sharing
- per-user Discord webhook notifications with message templates and test delivery
- SourceMod plugin/extension snapshots from Accelerator metadata when available
- `/health` admin tools for processing, uploads, auth, storage, and diagnostics

### Requirements

- PHP 8.4 CLI and PHP-FPM
- MariaDB 10.11 or newer
- Redis
- Composer
- Node.js and npm for asset builds
- Nginx and PHP-FPM for manual VPS installs
- Docker Compose for container installs
- 32-bit glibc/runtime libraries for Breakpad tools on Linux hosts (`minidump_stackwalk` is shipped as a 32-bit ELF binary)

Required PHP extensions include `ctype`, `iconv`, `intl`, `pdo_mysql`, `bcmath`, `xsl`, `zip`, and the standard Symfony runtime extensions.
On Debian/Ubuntu amd64 hosts, install the 32-bit runtime packages too: `libc6:i386`, `libstdc++6:i386`, `zlib1g:i386`, and `libgcc-s1:i386`.

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
AI_SETTINGS_KEY=change-this-to-a-long-random-value
BOOTSTRAP_ADMIN_EMAIL=admin@example.com
BOOTSTRAP_ADMIN_PASSWORD=change-this-password
APP_PORT=18080
AUTO_MIGRATE=1
```

Run:

```bash
docker compose --env-file .env.prod.docker -f compose.prod.yaml up -d --build
docker compose --env-file .env.prod.docker -f compose.prod.yaml ps
docker compose --env-file .env.prod.docker -f compose.prod.yaml exec app php bin/console doctrine:migrations:status --env=prod
```

The production compose file starts the web app plus a `processor` service that runs `crash:process --update --limit=10` every minute.
That processor pass also runs automatic `/health` storage cleanup when retention rules are enabled.

Open `http://SERVER_IP:18080/` or place a reverse proxy in front of the container.

`BOOTSTRAP_ADMIN_EMAIL` and `BOOTSTRAP_ADMIN_PASSWORD` create the first local user as already verified, so email + password sign-in works even if outgoing mail is not configured yet. This bootstrap user is not an admin automatically; grant admin access separately through `APP_ADMINS`, for example `APP_ADMINS="user:1"` on a clean install.

### Manual Ubuntu/Nginx/PHP-FPM Install

Install PHP 8.4, MariaDB, Redis, Nginx, Composer, Node.js, and npm. On Ubuntu 24.04, PHP 8.4 usually requires an additional package source such as `ppa:ondrej/php`.
If the server is amd64, also enable `i386` packages because the bundled `minidump_stackwalk` binary is 32-bit.

```bash
sudo dpkg --add-architecture i386
sudo apt-get update
sudo apt-get install -y libc6:i386 libstdc++6:i386 zlib1g:i386 libgcc-s1:i386

cd /var/www/throttle
sudo -u www-data env APP_ENV=prod APP_DEBUG=0 php8.4 "$(which composer)" install --no-dev --optimize-autoloader
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
AI_SETTINGS_KEY=change-this-to-a-long-random-value
BOOTSTRAP_ADMIN_EMAIL=admin@example.com
BOOTSTRAP_ADMIN_PASSWORD=change-this-password
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

Nginx must point the virtual host root at `public/`, pass PHP to PHP 8.4 FPM, allow large uploads, and, if you still use plain `http://` upload URLs for Accelerator, avoid redirecting `/submit`, `/symbols/submit`, and `/binary/submit` away from PHP on port `80`.

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name crash.example.com;

    root /var/www/throttle/public;
    index index.php;
    client_max_body_size 128M;

    ssl_certificate /etc/letsencrypt/live/crash.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crash.example.com/privkey.pem;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_connect_timeout 600s;
    }
}

server {
    listen 80;
    server_name crash.example.com;

    root /var/www/throttle/public;
    index index.php;
    client_max_body_size 128M;

    location = /submit {
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/throttle/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /var/www/throttle/public;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_URI /index.php;
        fastcgi_param PATH_INFO "";
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_connect_timeout 600s;
    }

    location = /symbols/submit {
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/throttle/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /var/www/throttle/public;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_URI /index.php;
        fastcgi_param PATH_INFO "";
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_connect_timeout 600s;
    }

    location = /binary/submit {
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/throttle/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /var/www/throttle/public;
        fastcgi_param REQUEST_URI $request_uri;
        fastcgi_param DOCUMENT_URI /index.php;
        fastcgi_param PATH_INFO "";
        fastcgi_read_timeout 600s;
        fastcgi_send_timeout 600s;
        fastcgi_connect_timeout 600s;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}
```

### SourceMod Accelerator core.cfg

Log in, open **Profile**, generate a token, adjust the `core.cfg` generator, and paste the generated block at the bottom of:

```text
addons/sourcemod/configs/core.cfg
```

Typical shape:

```text
"MinidumpSymbolUpload" "3"
"MinidumpBinaryUpload" "yes"
"MinidumpPresubmit" "yes"

"MinidumpUrl" "http://crash.example.com/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpSymbolUrl" "http://crash.example.com/symbols/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpBinaryUrl" "http://crash.example.com/binary/submit?token=YOUR_PROFILE_TOKEN"
```

If the upload URLs already contain a profile token, `MinidumpAccount` is optional. Ownership can then be resolved from the token instead of SteamID64.

`MinidumpPresubmit` is used for the early module inventory exchange before the full dump upload. In this branch it is used to decide which binaries/symbols should be uploaded early; it is not yet used as a full metadata-only or dump-reject gate. If you disable it, the site can still process dumps, but early binary/symbol auto-request flow will not work.

### Upload Endpoints and Symbol Flow

- `/submit` accepts crash dumps
- `/symbols/submit` accepts Breakpad `.sym` uploads
- `/binary/submit` accepts binaries and can generate `.sym` files from them
- symbols are ultimately stored and used on the site
- the game server can upload ready symbols or binaries, but symbol generation for Throttle happens on the site side when binaries are processed with `dump_syms`

### Hosted ProGamesZet Instance

The public hosted instance is available at [crash.progameszet.ru](https://crash.progameszet.ru). You can point Accelerator at that site if you do not want to run your own installation.

Important caveats for the hosted service:

- the server is physically located in Russia, so some ISPs or upstream providers may block or degrade connectivity to it
- storage on that hosted instance is capped at **1 GB** for each category: **Crash Artifacts**, **Symbols**, and **Binaries**
- when a category reaches its limit, the oldest stored data in that category is removed by retention cleanup

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

If email login links, confirmation emails, or password reset are enabled, make sure the queue is actually being processed. If needed:

```bash
sudo -u www-data php8.4 /var/www/throttle/bin/console messenger:consume async --env=prod --no-debug
```

### Health and Troubleshooting

- `/health` controls enabled sign-in methods and upload/runtime settings
- `/health` binary upload request policy controls which modules Accelerator is asked to upload during presubmit
- `/health` includes upload failure backoff, SMTP/Discord/auth diagnostics, symbol cache tools, binary upload tools, storage cleanup/retention, and AI analysis toggle
- `Storage cleanup` can delete old crash artifacts, stored symbols, and stored binaries by age or total size; manual runs are available in `/health`
- when cleanup removes heavy crash artifacts, the related raw pages now return a normal `410 Gone` site page instead of a raw exception screen
- user AI configs live in **Profile -> AI analysis**
- pending crashes usually mean `crash:process` is not running or failed
- when symbols or binaries arrive after an earlier crash was already processed, Throttle marks affected crash reports for automatic reprocessing on the next `crash:process --update` pass
- automatic storage cleanup runs inside `crash:process`; on manual installs you therefore need the timer/service or another scheduler that actually runs that command
- `bin/carburetor`, `bin/minidump_stackwalk`, `bin/dump_syms`, `bin/breakpad_moduleid`, and `bin/nm` must be executable
- if `minidump_stackwalk` fails with `exit 127`, `not found`, or `cannot execute: required file not found`, the binary usually exists but the server is missing 32-bit runtime libraries such as `libc6:i386`
- `var/`, `cache/`, `dumps/`, and `symbols/` must be writable by the PHP-FPM user
- if templates fail with missing Encore entrypoints, run `npm ci && npm run build` and verify `public/build/entrypoints.json` exists
- if Composer uses PHP 8.1 on a PHP 8.4 project, run Composer through PHP 8.4

### Profile Discord Webhooks

- **Profile** includes per-user Discord webhook notifications for processed crashes
- each user can store up to 5 webhook targets
- supported placeholders are `{crash_id}`, `{likely_cause}`, `{likely_cause_details}`, `{likely_cause_supporting}`, `{likely_cause_full}`, `{stack_trace}`, and `{console}`
- placeholders inject only the raw content they represent; they do not prepend labels like `Likely cause:` or `Stack trace:`
- `{likely_cause}` is a short one-line summary, while `{likely_cause_details}`, `{likely_cause_supporting}`, and `{likely_cause_full}` expose progressively more detail
- each webhook can independently limit both the console tail and the number of stack trace lines included in Discord
- the webhook URL is stored encrypted on the site
- Discord webhook `content` is limited to 2000 characters, and stack traces are truncated before final delivery when needed
- the **Test message** button sends a sample payload immediately
- automatic delivery happens after a successful `crash:process` pass, so it depends on the same processor/timer that handles crash processing

### Authentication Setup

All sign-in methods are toggled in `/health`.

#### Steam

- enable `Steam login` in `/health`
- the server must be able to reach `https://steamcommunity.com/openid/login`
- `STEAM_API_KEY` is not required for the OpenID handshake itself, but is recommended for Steam profile lookups

#### Discord

- set `OAUTH_DISCORD_CLIENT_ID`
- set `OAUTH_DISCORD_CLIENT_SECRET`
- add the exact callback URL:
  - `https://YOUR_HOST/login/discord`
- enable `Discord login` in `/health`

#### Email login links

- set `MAILER_DSN`
- set `MAILER_FROM`
- enable `Email login links` in `/health`

#### Email + password

- enable `Email + password login` in `/health`
- enable `Email + password registration` in `/health`
- enable `Password reset` in `/health` if users should be able to recover access by email
- self-registration requires working outgoing email because new accounts must confirm their email address before normal sign-in is allowed

#### Upload-token login

- enable `Upload-token login` in `/health`
- this reuses the same profile upload token that can also own crash uploads
- treat upload tokens like real credentials

### Security Notes

- never commit `.env.local`, `.env.local.php`, real tokens, database passwords, crash dumps, symbols, binaries, or production logs
- keep `APP_SECRET`, `AI_SETTINGS_KEY`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN`, and OAuth secrets in server-side environment files only
- use HTTPS in production even if a local or legacy setup still relies on HTTP upload URLs internally
- keep PHP, Composer dependencies, Node dependencies, Breakpad tools, and SourceMod Accelerator updated

## Русский

### Что Это

Throttle — это веб-сервис для приёма и анализа crash-report'ов Source engine серверов через SourceMod Accelerator. Он принимает Breakpad minidump'ы, хранит и обрабатывает крэши, принимает `.sym` файлы, умеет генерировать symbols из загруженных бинарников и даёт веб-интерфейс для владельцев серверов и администраторов. Эта ветка поддерживается как прагматичный vibe-code проект: кодовая база развивается итеративно и в первую очередь заточена под рабочую эксплуатацию.

Актуальная ветка включает:

- вход через Steam, Discord, email-link, email + password и upload token
- публичный переключатель Light/Dark/System
- генератор настроек SourceMod Accelerator `core.cfg`
- `/submit`, `/symbols/submit` и `/binary/submit`
- `/health` для runtime-настроек, диагностики и админских операций
- AI-анализ крэшей с пользовательскими provider configs
- пользовательские Discord webhook-уведомления после обработки крэшей

### Установка Через Docker Compose

Склонируйте проект и создайте production env-файл:

```bash
git clone https://github.com/MrPanica/throttle throttle
cd throttle
cp .env.prod.docker.example .env.prod.docker
```

Минимальный пример `.env.prod.docker`:

```dotenv
APP_SECRET=change-this-to-a-long-random-value
MARIADB_PASSWORD=change-this
MARIADB_ROOT_PASSWORD=change-this-too
STEAM_API_KEY=optional-steam-web-api-key
APP_ADMINS=steam:YOUR_STEAMID64
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
AI_SETTINGS_KEY=change-this-to-a-long-random-value
BOOTSTRAP_ADMIN_EMAIL=admin@example.com
BOOTSTRAP_ADMIN_PASSWORD=change-this-password
APP_PORT=18080
AUTO_MIGRATE=1
```

Запуск:

```bash
docker compose --env-file .env.prod.docker -f compose.prod.yaml up -d --build
docker compose --env-file .env.prod.docker -f compose.prod.yaml ps
docker compose --env-file .env.prod.docker -f compose.prod.yaml exec app php bin/console doctrine:migrations:status --env=prod
```

Production compose поднимает веб-приложение, MariaDB, Redis и `processor`, который раз в минуту запускает `crash:process --update --limit=10`.
Этот проход `processor` также запускает automatic storage cleanup из `/health`, если retention rules включены.

`BOOTSTRAP_ADMIN_EMAIL` и `BOOTSTRAP_ADMIN_PASSWORD` создают первого локального пользователя, если таблица пользователей ещё пуста. Этот bootstrap-пользователь сразу помечается подтверждённым, поэтому вход по email + password работает даже без настроенной почты. Админом он не становится автоматически: права нужно выдать отдельно через `APP_ADMINS`, обычно `APP_ADMINS="user:1"` на чистой установке.

### Ручная Установка На Ubuntu/Nginx/PHP-FPM

Установите PHP 8.4, MariaDB, Redis, Nginx, Composer, Node.js и npm. На Ubuntu 24.04 PHP 8.4 обычно требует дополнительного репозитория, например `ppa:ondrej/php`.

Сборка приложения:

```bash
cd /var/www/throttle
sudo -u www-data env APP_ENV=prod APP_DEBUG=0 php8.4 "$(which composer)" install --no-dev --optimize-autoloader
npm ci
npm run build
```

Создайте `/var/www/throttle/.env.local`:

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
BOOTSTRAP_ADMIN_EMAIL=admin@example.com
BOOTSTRAP_ADMIN_PASSWORD=change-this-password
```

Подготовьте базу и production-кэш:

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

php8.4 $(which composer) dump-env prod
sudo -u www-data php8.4 bin/console doctrine:migrations:migrate --no-interaction --env=prod
sudo -u www-data php8.4 bin/console cache:clear --env=prod --no-debug
sudo -u www-data php8.4 bin/console cache:warmup --env=prod --no-debug
chmod +x bin/carburetor bin/minidump_stackwalk bin/dump_syms bin/breakpad_moduleid bin/nm
chown -R www-data:www-data var cache dumps symbols
chmod -R ug+rwX var cache dumps symbols
systemctl restart php8.4-fpm nginx
```

Nginx должен смотреть в `public/`, проксировать PHP в PHP-FPM и разрешать крупные uploads:

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

Зайдите в **Profile**, сгенерируйте upload token и используйте генератор `core.cfg`.

Типичная форма настроек:

```text
"MinidumpSymbolUpload" "3"
"MinidumpBinaryUpload" "yes"
"MinidumpPresubmit" "yes"

"MinidumpUrl" "http://crash.example.com/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpSymbolUrl" "http://crash.example.com/symbols/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpBinaryUrl" "http://crash.example.com/binary/submit?token=YOUR_PROFILE_TOKEN"
```

Если upload URLs уже содержат profile token, `MinidumpAccount` можно не указывать. В этом режиме владелец крэша определяется по token, а не по SteamID64.

`MinidumpPresubmit` нужен для раннего обмена списком модулей перед загрузкой самого дампа. В этой ветке он используется именно для раннего запроса binaries/symbols и ещё не работает как полноценный metadata-only или dump-reject gate. Если его выключить, сайт всё ещё сможет обработать сам dump, но ранний auto-request бинарников и symbols работать не будет.

### Где Формируются Symbols

- сайт в итоге хранит и использует symbols у себя
- игровой сервер через Accelerator может прислать готовые `.sym` или сами бинарники
- если пришли бинарники, символы для Throttle генерируются уже на стороне сайта через `dump_syms`

### Публичный Инстанс ProGamesZet

Публичный инстанс доступен по адресу [crash.progameszet.ru](https://crash.progameszet.ru). К нему можно подключить Accelerator и отправлять крэши, если не хочется поднимать свою установку.

Важно учитывать:

- сервер физически расположен в России, поэтому некоторые провайдеры или апстримы могут блокировать или ухудшать подключение к нему
- на этом hosted-инстансе хранилище ограничено **1 ГБ** для каждой категории: **Crash Artifacts**, **Symbols** и **Binaries**
- при достижении лимита в категории retention cleanup удаляет самые старые данные этой категории

### Обработка Крэшей И Очередь

В Docker обработку делает service `processor`. Для ручной установки используйте systemd timer:

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

Если используются email login links, confirmation emails или password reset, убедитесь, что очередь действительно обрабатывается. При необходимости можно отдельно запустить:

```bash
sudo -u www-data php8.4 /var/www/throttle/bin/console messenger:consume async --env=prod --no-debug
```

### /health И Администрирование

`/health` доступен только администраторам. Админов можно задать через:

```dotenv
APP_ADMINS="steam:STEAMID64,user:1"
```

Поддерживаются `user:<id>`, SteamID64 и `steam:<SteamID64>`.

На `/health` доступны:

- переключатели способов входа
- runtime-настройки upload processing
- binary upload request policy
- upload failure backoff
- проверки очереди, storage и runtime paths
- диагностика SMTP / Discord / auth
- переключатель AI crash analysis
- инструменты для symbol cache, экспорта `.sym.gz` и ручной загрузки бинарников
- storage cleanup / retention для crash artifacts, symbols и binaries

`Storage cleanup` можно запускать вручную из `/health`. Автоматическая очистка выполняется внутри `crash:process`, поэтому для обычной VPS-установки timer или другой scheduler, запускающий `crash:process`, нужен не только для обработки крэшей, но и для retention cleanup.
Если cleanup удалил тяжёлые crash artifacts, связанные raw-страницы теперь отдают штатную страницу `410 Gone`, а не сырую exception page.

### Discord Webhooks В Профиле

- в **Profile** можно сохранить до 5 Discord webhook'ов для уведомлений о новых обработанных крэшах
- поддерживаются плейсхолдеры `{crash_id}`, `{likely_cause}`, `{likely_cause_details}`, `{likely_cause_supporting}`, `{likely_cause_full}`, `{stack_trace}` и `{console}`
- плейсхолдеры подставляют только собственное содержимое без служебных заголовков вроде `Likely cause:` или `Stack trace:`
- `{likely_cause}` теперь даёт короткую однострочную сводку, а `{likely_cause_details}`, `{likely_cause_supporting}` и `{likely_cause_full}` позволяют добавить подробности
- для каждого webhook можно отдельно ограничить и количество строк консоли, и количество строк stack trace
- URL webhook'а хранится на сайте в зашифрованном виде
- Discord webhook `content` ограничен 2000 символами, поэтому длинные stack trace автоматически сокращаются перед отправкой
- кнопка **Test message** сразу отправляет тестовый payload
- автоматическая отправка происходит после успешного прохода `crash:process`, поэтому зависит от того же `processor` или timer/service, который обрабатывает крэши
Если symbols или binaries догрузились уже после первого processing pass, Throttle автоматически помечает затронутые crash reports на переобработку в следующем `crash:process --update`.

### AI Analysis

Пользовательские AI-конфиги настраиваются в **Profile -> AI analysis**. Поддерживаются:

- OpenAI
- Anthropic
- Google Gemini
- OpenRouter
- Custom OpenAI-compatible

Можно хранить несколько configs, выбирать default config и default prompt, задавать provider-specific extra request JSON и запускать `Ask AI` на crash details или raw output.

### Безопасность

- не коммитьте `.env.local`, `.env.local.php`, реальные токены, database passwords, crash dumps, symbols, binaries и production logs
- храните `APP_SECRET`, `AI_SETTINGS_KEY`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN` и OAuth secrets только в server-side env files
- используйте HTTPS в production, даже если для внутренних или legacy-конфигов generator сейчас может формировать `http://`
- регулярно обновляйте PHP, Composer dependencies, Node dependencies, Breakpad tools и SourceMod Accelerator
