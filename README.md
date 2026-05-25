# Throttle

<img width="1899" height="912" alt="Throttle dashboard screenshot" src="https://github.com/user-attachments/assets/2936ba6f-c91e-4e7c-85cd-6c12ffba94fa" />

More screenshots: [docs/screenshots](docs/screenshots/)

## English

### What This Is

Throttle is a Symfony/Silex crash-reporting service for Source engine servers using SourceMod Accelerator. It accepts Breakpad minidumps, stores and processes crash reports, accepts Breakpad symbol files, can generate symbols from uploaded binaries, and provides a web dashboard for owners and admins.

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

Required PHP extensions include `ctype`, `iconv`, `intl`, `pdo_mysql`, `bcmath`, `xsl`, `zip`, and the standard Symfony runtime extensions.

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

Open `http://SERVER_IP:18080/` or place a reverse proxy in front of the container.

`BOOTSTRAP_ADMIN_EMAIL` and `BOOTSTRAP_ADMIN_PASSWORD` create the first local user as already verified, so email + password sign-in works even if outgoing mail is not configured yet. This bootstrap user is not an admin automatically; grant admin access separately through `APP_ADMINS`, for example `APP_ADMINS="user:1"` on a clean install.

### Manual Ubuntu/Nginx/PHP-FPM Install

Install PHP 8.4, MariaDB, Redis, Nginx, Composer, Node.js, and npm. On Ubuntu 24.04, PHP 8.4 usually requires an additional package source such as `ppa:ondrej/php`.

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

`MinidumpPresubmit` is used for the early module inventory exchange before the full dump upload. If you disable it, the site can still process dumps, but early binary/symbol auto-request flow will not work.

### Upload Endpoints and Symbol Flow

- `/submit` accepts crash dumps
- `/symbols/submit` accepts Breakpad `.sym` uploads
- `/binary/submit` accepts binaries and can generate `.sym` files from them
- symbols are ultimately stored and used on the site
- the game server can upload ready symbols or binaries, but symbol generation for Throttle happens on the site side when binaries are processed with `dump_syms`

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
- `/health` includes upload failure backoff, SMTP/Discord/auth diagnostics, symbol cache tools, binary upload tools, and AI analysis toggle
- user AI configs live in **Profile -> AI analysis**
- pending crashes usually mean `crash:process` is not running or failed
- `bin/carburetor`, `bin/minidump_stackwalk`, `bin/dump_syms`, `bin/breakpad_moduleid`, and `bin/nm` must be executable
- `var/`, `cache/`, `dumps/`, and `symbols/` must be writable by the PHP-FPM user
- if templates fail with missing Encore entrypoints, run `npm ci && npm run build` and verify `public/build/entrypoints.json` exists
- if Composer uses PHP 8.1 on a PHP 8.4 project, run Composer through PHP 8.4

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

Throttle — это веб-сервис для приёма и анализа crash-report'ов Source engine серверов через SourceMod Accelerator. Он принимает Breakpad minidump'ы, хранит и обрабатывает крэши, принимает `.sym` файлы, умеет генерировать symbols из загруженных бинарников и даёт веб-интерфейс для владельцев серверов и администраторов.

Актуальная ветка включает:

- вход через Steam, Discord, email-link, email + password и upload token
- публичный переключатель Light/Dark/System
- генератор настроек SourceMod Accelerator `core.cfg`
- `/submit`, `/symbols/submit` и `/binary/submit`
- `/health` для runtime-настроек, диагностики и админских операций
- AI-анализ крэшей с пользовательскими provider configs

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

`BOOTSTRAP_ADMIN_EMAIL` и `BOOTSTRAP_ADMIN_PASSWORD` создают первого локального пользователя, если таблица пользователей ещё пуста. Этот bootstrap-пользователь сразу помечается подтверждённым, поэтому вход по email + password работает даже без настроенной почты. Админом он не становится автоматически: права нужно выдать отдельно через `APP_ADMINS`, обычно `APP_ADMINS="user:1"` на чистой установке.

### Ручная Установка На Ubuntu/Nginx/PHP-FPM

Установите PHP 8.4, MariaDB, Redis, Nginx, Composer, Node.js и npm. На Ubuntu 24.04 PHP 8.4 обычно требует дополнительного репозитория, например `ppa:ondrej/php`.

Сборка приложения:

```bash
cd /var/www/throttle
APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader
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

`MinidumpPresubmit` нужен для раннего обмена списком модулей перед загрузкой самого дампа. Если его выключить, сайт всё ещё сможет обработать сам dump, но ранний auto-request бинарников и symbols работать не будет.

### Где Формируются Symbols

- сайт в итоге хранит и использует symbols у себя
- игровой сервер через Accelerator может прислать готовые `.sym` или сами бинарники
- если пришли бинарники, символы для Throttle генерируются уже на стороне сайта через `dump_syms`

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
