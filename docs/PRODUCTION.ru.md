# Production установка

## Docker Compose

```bash
git clone <repo-url> throttle
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

- `app` - веб-приложение.
- `processor` - обработчик очереди, который раз в минуту выполняет `crash:process --update --limit=10`.
- `db` - MariaDB.
- `redis` - Redis.

Постоянные Docker volumes хранят базу, Redis, `var/`, `dumps/` и `symbols/`. Это важно: настройки Binary upload request policy из `/health` сохраняются в `var/symbol-request-policy.json`, а настройки обработки upload endpoints - в `var/upload-settings.json`.

## Ручная установка Ubuntu/Nginx/PHP-FPM

Нужны PHP 8.4 CLI/FPM, MariaDB 10.11+, Redis, Composer, Node.js, npm и Nginx. PHP extensions: `ctype`, `iconv`, `intl`, `pdo_mysql`, `bcmath`, `xsl`, `zip`.

```bash
cd /var/www/throttle
php8.4 $(which composer) install --no-dev --optimize-autoloader
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

Подготовьте production окружение:

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

Nginx должен смотреть в `public/` и разрешать крупные upload'ы:

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

На `/health` видны checks, очередь, бинарники, Binary upload request policy и настройки обработки upload endpoints. Через UI можно включать потоковую обработку `.sym` и задавать `memory_limit` только для `/symbols/submit` и `/binary/submit`. Policy сохраняется в `var/symbol-request-policy.json`, upload-настройки сохраняются в `var/upload-settings.json`; оба файла надо сохранять при бэкапах.

Additional runtime notes:

- The Light/Dark/System theme switcher is available before login and defaults to system theme.
- `/health` upload settings can disable anonymous `/submit` minidump uploads; when disabled, `/submit` requires a profile upload token or `SYMBOL_UPLOAD_TOKEN`.
- `APP_ADMINS` accepts values separated by comma, space, or semicolon: `user:<id>`, SteamID64, or `steam:<SteamID64>`. Admins can access `/health`, global dashboard/audit data, crash management, reprocess/delete actions, and delete any signature note.
