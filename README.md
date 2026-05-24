# Throttle
<img width="1899" height="912" alt="image" src="https://github.com/user-attachments/assets/2936ba6f-c91e-4e7c-85cd-6c12ffba94fa" />

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
- symbol coverage view for report owners/admins
- admin tools for manual binary uploads, symbol cache refresh, and stored symbol export
- likely crash cause scoring
- optional AI crash analysis with per-user API configs, saved history, and public history sharing
- SourceMod plugin/extension snapshots from Accelerator metadata when available
- admin health page and systemd timer files for processing

### Requirements

- PHP 8.4 CLI and PHP-FPM
- MariaDB 10.11 or newer
- Redis
- Composer
- Node.js and npm for asset builds
- Nginx and PHP-FPM for manual VPS installs
- Docker Compose for Docker installs

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

The production compose file starts the web app plus a `processor` service that runs `crash:process --update --limit=10` every minute. Persistent Docker volumes keep the database, Redis data, crash dumps, symbol files, and runtime `var/` data, including `/health` symbol request policy and upload processing settings.

Open `http://SERVER_IP:18080/` or put Nginx/Traefik/Caddy in front of the container. `BOOTSTRAP_ADMIN_EMAIL` and `BOOTSTRAP_ADMIN_PASSWORD` create the first local user as already verified, so email + password sign-in works even if outgoing mail is not configured yet. This bootstrap user is not an admin automatically; grant admin access separately through `APP_ADMINS`, for example `APP_ADMINS="user:1"` on a clean install.

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

Log in, open **Profile**, generate a token, adjust the `core.cfg` generator, click **Generate settings**, then paste the generated block at the bottom of:

```text
addons/sourcemod/configs/core.cfg
```

Example shape:

```text
"MinidumpSymbolUpload" "3"
"MinidumpBinaryUpload" "yes"
"MinidumpPresubmit" "yes"

"MinidumpUrl" "https://crash.example.com/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpSymbolUrl" "https://crash.example.com/symbols/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpBinaryUrl" "https://crash.example.com/binary/submit?token=YOUR_PROFILE_TOKEN"
```

If the server's libcurl does not support HTTPS, use HTTP behind your own trusted network/reverse proxy setup, or update the server runtime so Accelerator can upload over HTTPS.

`MinidumpAccount` is optional when a profile token is present in the generated upload URLs. Token-owned uploads work even if `MinidumpAccount` is blank.

### Upload Token Behavior

- `/submit` accepts crash dumps without a token by default so legacy Accelerator crash uploads still work
- admins can disable anonymous minidump uploads in `/health`; when disabled, `/submit` requires either a profile upload token or the global `SYMBOL_UPLOAD_TOKEN`
- `/submit?token=PROFILE_TOKEN` records profile token usage for crash uploads
- `/symbols/submit` requires a profile token, an admin session, or the global `SYMBOL_UPLOAD_TOKEN`
- `/binary/submit` requires a profile token, an admin session, or the global `SYMBOL_UPLOAD_TOKEN`
- rejected symbol/binary uploads are written to the token activity audit when possible
- never publish upload tokens in public docs, plugin source, screenshots, or commits

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

- `/health` controls which sign-in methods are enabled: Steam, Discord, email login links, email + password login, email + password registration, password reset, and upload-token login
- new local accounts use email as the interactive identifier; registration and password login no longer ask for a separate username
- the Light/Dark/System theme switcher is available before login and stores `light`, `dark`, or `system` in browser local storage; the default is `system`
- `/health` is admin-only and shows queue, storage, binary, and runtime health
- `/health` upload processing settings now include:
  - streaming `.sym` upload processing
  - upload endpoint memory limit
  - anonymous minidump upload toggle for `/submit`
  - upload failure backoff for repeated symbol/binary upload failures on the same `module + identifier`
  - `crash_ai_analysis_enabled`, which controls whether `Ask AI` is available on crash details and raw pages
- `/health` binary upload request policy controls which Linux modules Accelerator is asked to upload during presubmit; defaults are intentionally empty, so a fresh install will not request symbol or binary uploads until allow rules are configured
- `/health` also includes admin tools to:
  - refresh symbol caches and rescan stored symbols
  - clear upload failure backoff state
  - upload binaries manually and generate symbols from them
  - browse and export stored `.sym.gz` files
- saved health runtime data is stored in:
  - `var/symbol-request-policy.json`
  - `var/upload-settings.json`
  - `var/upload-failure-backoff.json`
- AI API keys are stored server-side in encrypted form; set `AI_SETTINGS_KEY` in `.env.local`, `.env.local.php`, Docker env, or another server-side environment source
- user AI configs live in **Profile -> AI analysis**
- `Ask AI` is available to crash owners and admins when `/health` has AI analysis enabled
- grant admin access without editing the database by setting `APP_ADMINS` in `.env.local` or the service environment; accepted values are separated by comma, space, or semicolon: numeric local user ids, `user:<id>`, SteamID64 values, or `steam:<SteamID64>`
- bootstrap users created from `BOOTSTRAP_ADMIN_EMAIL` are regular users; on a clean install their local id is normally `1`, so `APP_ADMINS="user:1"` is the usual way to promote the first account
- admins can access `/health`, see global dashboards/audit data, view and manage crash reports they do not own, reprocess/delete crashes, and delete any crash signature note
- pending crashes usually mean `crash:process` is not running or failed
- `bin/carburetor`, `bin/minidump_stackwalk`, `bin/dump_syms`, `bin/breakpad_moduleid`, and `bin/nm` must be executable
- `var/`, `cache/`, `dumps/`, and `symbols/` must be writable by the PHP-FPM user
- if templates fail with missing Encore entrypoints, run `npm ci && npm run build` and verify `public/build/entrypoints.json` exists
- if Composer uses PHP 8.1 on a PHP 8.4 project, run Composer through PHP 8.4 and force the production environment during install: `APP_ENV=prod APP_DEBUG=0 php8.4 $(which composer) install --no-dev --optimize-autoloader`
- if PHP-FPM logs duplicate or missing extensions, fix `/etc/php/8.4/fpm/php.ini` and use package-managed `conf.d` extension files

### Authentication Setup

Throttle can be configured to allow one or several sign-in methods at the same time. The master toggles live in `/health`.

#### Steam

- enable `Steam login` in `/health`
- the server must be able to reach `https://steamcommunity.com/openid/login` from the PHP runtime
- `STEAM_API_KEY` is not required for the OpenID handshake itself, but it is still recommended for Steam profile lookups and related integrations

#### Discord

- set `OAUTH_DISCORD_CLIENT_ID`
- set `OAUTH_DISCORD_CLIENT_SECRET`
- open your Discord application OAuth2 page, for example `https://discord.com/developers/applications/BotID/oauth2`
- take the client id and client secret from that Discord application
- in the same Discord OAuth2 settings, add the exact callback URL:
  - `https://YOUR_HOST/login/discord`
- enable `Discord login` in `/health`

#### Email login links

- set `MAILER_DSN`
- set `MAILER_FROM`
- enable `Email login links` in `/health`
- outgoing mail is queued through Symfony Messenger:
  - Docker Compose deployments already include the `processor` service
  - manual installs should also run the documented queue/processor worker path or a dedicated `messenger:consume async` service so queued `SendEmailMessage` jobs are actually delivered

#### Email + password

- enable `Email + password login` in `/health`
- enable `Email + password registration` in `/health`
- enable `Password reset` in `/health` if users should be able to recover access by email
- self-registration requires working outgoing email because new accounts must confirm their email address before normal sign-in is allowed
- `BOOTSTRAP_ADMIN_EMAIL` and `BOOTSTRAP_ADMIN_PASSWORD` create the first local user only when the user table is empty; this bootstrap account is marked as already verified, so email + password sign-in works immediately even if mail delivery is not configured yet
- this bootstrap user is not an admin by default; grant admin rights separately with `APP_ADMINS`, for example `APP_ADMINS="user:1"` on a clean install

#### Upload-token login

- enable `Upload-token login` in `/health`
- this uses the same profile upload token that also owns crash uploads
- that is convenient, but weaker than a separate dedicated login token, so treat upload tokens like real credentials

### Security Notes

- never commit `.env.local`, `.env.local.php`, real tokens, database passwords, crash dumps, symbols, binaries, or production logs
- keep `APP_SECRET`, `AI_SETTINGS_KEY`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN`, and OAuth secrets in server environment files only
- put the app behind HTTPS in production
- keep PHP, Composer dependencies, Node dependencies, Breakpad tools, and SourceMod Accelerator updated

## Русский

### Что Это

Throttle - это веб-сервис для приёма и анализа crash-report'ов Source engine серверов через SourceMod Accelerator. Он принимает Breakpad minidump'ы, хранит и обрабатывает крэши, принимает `.sym` файлы, умеет генерировать symbols из загруженных бинарников и даёт веб-интерфейс для владельцев серверов и администраторов.

Актуальная ветка включает:

- вход через Steam, Discord, email-link, email + password и upload token
- генератор настроек SourceMod Accelerator `core.cfg`
- ownership по profile token, даже если `MinidumpAccount` пустой
- `/health` для runtime-настроек, диагностики и админских операций
- AI-анализ крэшей с пользовательскими provider configs

### Быстрый Старт Через Docker

Склонируйте проект, создайте production env-файл и заполните основные переменные:

```bash
git clone https://github.com/MrPanica/throttle throttle
cd throttle
cp .env.prod.docker.example .env.prod.docker
```

Минимальный пример:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-this-to-a-long-random-secret
APP_PORT=18080

MARIADB_PASSWORD=change-this
MARIADB_ROOT_PASSWORD=change-this-too
STEAM_API_KEY=optional-steam-web-api-key
APP_ADMINS=steam:YOUR_STEAMID64
SYMBOL_UPLOAD_TOKEN=optional-global-symbol-token
AI_SETTINGS_KEY=change-this-to-a-long-random-value
BOOTSTRAP_ADMIN_EMAIL=admin@example.com
BOOTSTRAP_ADMIN_PASSWORD=change-this-password
AUTO_MIGRATE=1
```

Поднимите сервисы:

```bash
docker compose --env-file .env.prod.docker -f compose.prod.yaml up -d --build
```

Docker-конфиг поднимает веб-приложение, MariaDB, Redis и `processor`, который раз в минуту запускает `crash:process --update --limit=10`.

`BOOTSTRAP_ADMIN_EMAIL` и `BOOTSTRAP_ADMIN_PASSWORD` создают первого локального пользователя только если таблица пользователей ещё пуста. Этот bootstrap-пользователь сразу помечается подтверждённым, поэтому вход по email + password работает даже без настроенной почты. Админские права ему нужно выдать отдельно через `APP_ADMINS`, обычно `APP_ADMINS="user:1"` на чистой установке.

### Ручная Установка На Ubuntu/Nginx/PHP-FPM

Установите зависимости:

```bash
sudo apt update
sudo apt install -y git curl unzip nginx mariadb-server redis-server
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-curl php8.4-mbstring php8.4-intl php8.4-zip php8.4-bcmath
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

Склонируйте проект и соберите production assets:

```bash
git clone https://github.com/MrPanica/throttle.git /var/www/throttle
cd /var/www/throttle
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Создайте `/var/www/throttle/.env.local`:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-this-to-a-long-random-secret
CODE_EDITOR=phpstorm

DATABASE_URL="mysql://throttle:CHANGE_DB_PASSWORD@127.0.0.1:3306/throttle?serverVersion=mariadb-10.11.2&charset=utf8mb4"
REDIS_URL=redis://127.0.0.1:6379
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

MAILER_DSN=null://null
MAILER_FROM=throttle@example.com
SENTRY_DSN=
STEAM_API_KEY=
APP_ADMINS="steam:YOUR_STEAMID64"
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

### SourceMod Accelerator И MinidumpAccount

Настройки `core.cfg` генерируются в **Profile**.

Если upload URLs уже содержат profile token, `MinidumpAccount` можно не указывать:

```text
"MinidumpUrl" "https://crash.example.com/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpSymbolUrl" "https://crash.example.com/symbols/submit?token=YOUR_PROFILE_TOKEN"
"MinidumpBinaryUrl" "https://crash.example.com/binary/submit?token=YOUR_PROFILE_TOKEN"
```

В таком режиме владелец краша определяется по token, а не по SteamID64.

### Настройка Способов Входа

Все методы входа включаются и выключаются в `/health`.

#### Steam

- включите `Steam login` в `/health`
- сервер должен иметь исходящий доступ к `https://steamcommunity.com/openid/login`
- `STEAM_API_KEY` не обязателен для самого OpenID-входа, но полезен для Steam profile lookups и связанных интеграций

#### Discord

- задайте `OAUTH_DISCORD_CLIENT_ID`
- задайте `OAUTH_DISCORD_CLIENT_SECRET`
- откройте OAuth2-страницу вашего Discord-приложения, например `https://discord.com/developers/applications/BotID/oauth2`
- возьмите `client id` и `client secret` из этого Discord-приложения
- в тех же настройках OAuth2 добавьте callback URL:
  - `https://YOUR_HOST/login/discord`
- включите `Discord login` в `/health`

#### Email Login Links

- задайте `MAILER_DSN`
- задайте `MAILER_FROM`
- включите `Email login links` в `/health`
- письма отправляются через очередь Symfony Messenger, поэтому delivery требует работающего worker/processor path

#### Email + Password

- включите `Email + password login` в `/health`
- включите `Email + password registration` в `/health`
- включите `Password reset` в `/health`, если пользователи должны восстанавливать доступ по почте
- обычная self-registration требует рабочей исходящей почты, потому что email должен быть подтверждён до обычного входа
- bootstrap-пользователь через `BOOTSTRAP_ADMIN_EMAIL` + `BOOTSTRAP_ADMIN_PASSWORD` создаётся сразу подтверждённым
- bootstrap-пользователь не админ по умолчанию; для доступа к `/health` и admin-функциям добавьте его в `APP_ADMINS`, обычно `APP_ADMINS="user:1"` на чистой базе

#### Upload-Token Login

- включите `Upload-token login` в `/health`
- этот способ использует тот же profile token, что и ownership crash uploads
- это удобно, но слабее по безопасности, чем отдельный dedicated login token

### Очередь И Почта

Для Docker обработку крэшей делает service `processor`. Для ручной установки нужно включить systemd timer:

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

Если вы используете email login links, confirmation emails или password reset, убедитесь, что очередь действительно обрабатывается. При необходимости можно отдельно запускать:

```bash
sudo -u www-data php8.4 /var/www/throttle/bin/console messenger:consume async --env=prod --no-debug
```

### Health

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
- checks по очереди, storage и runtime paths
- диагностика SMTP / Discord / auth
- AI crash analysis toggle

### AI Analysis

Пользовательские AI-конфиги настраиваются в **Profile -> AI analysis**. Поддерживаются:

- OpenAI
- Anthropic
- Google Gemini
- OpenRouter
- Custom OpenAI-compatible

Можно сохранять несколько configs, выбирать default config и default prompt, задавать provider-specific extra request JSON, запускать `Ask AI` на crash details или raw output и управлять history entries.

### Безопасность

- не коммитьте `.env.local`, `.env.local.php`, реальные токены, database passwords, crash dumps, symbols, binaries и production logs
- храните `APP_SECRET`, `AI_SETTINGS_KEY`, `DATABASE_URL`, `STEAM_API_KEY`, `SYMBOL_UPLOAD_TOKEN` и OAuth secrets только в server-side env files
- используйте HTTPS в production
- регулярно обновляйте PHP, Composer dependencies, Node dependencies, Breakpad tools и SourceMod Accelerator
