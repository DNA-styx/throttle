# Throttle: установка и настройка на production

Ниже описан практический сценарий развёртывания через Docker Compose.

## 1. Требования

- Linux-сервер с Docker Engine и Docker Compose
- свободный TCP-порт под веб-интерфейс
- домен или IP, по которому будет доступен сайт
- подготовленные OAuth / Steam credentials, если нужен вход через внешние провайдеры

## 2. Подготовка переменных окружения

Скопируйте шаблон:

```bash
cp .env.prod.docker.example .env.prod.docker
```

Заполните минимум:

- `APP_PORT` — внешний порт приложения
- `APP_SECRET` — длинный случайный секрет
- `MARIADB_PASSWORD` — пароль пользователя БД
- `MARIADB_ROOT_PASSWORD` — пароль root в MariaDB
- `MAILER_FROM` — адрес отправителя писем

Если нужен вход через Steam, заполните:

- `STEAM_API_KEY`
- `SYMBOL_UPLOAD_TOKEN` — длинный случайный токен для `POST /symbols/submit`

Если нужны OAuth-провайдеры, заполните соответствующие пары:

- `OAUTH_GITHUB_CLIENT_ID`
- `OAUTH_GITHUB_CLIENT_SECRET`
- `OAUTH_DISCORD_CLIENT_ID`
- `OAUTH_DISCORD_CLIENT_SECRET`
- `OAUTH_ALLIEDMODS_CLIENT_ID`
- `OAUTH_ALLIEDMODS_CLIENT_SECRET`

Если Sentry не используется, `SENTRY_DSN` можно оставить пустым.

## 3. Запуск production-стека

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml up -d --build
```

По умолчанию:

- контейнер `app` поднимает Symfony в `APP_ENV=prod`
- миграции запускаются автоматически через `AUTO_MIGRATE=1`
- дампы и symbols хранятся в отдельных Docker volumes

## 4. Проверка после запуска

Проверить статус:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml ps
```

Посмотреть логи приложения:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml logs -f app
```

Проверить консоль Symfony:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml exec -T app php bin/console about
```

После старта должны открываться:

- `/`
- `/login`
- `/login/steam` при настроенном `STEAM_API_KEY`

## 5. Обновление проекта

После обновления кода:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml up -d --build
```

Если нужно вручную прогнать миграции:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml exec -T app php bin/console doctrine:migrations:migrate --no-interaction
```

## 6. Работа с дампами и символами

### Загрузка дампов

Дампы отправляет игровой сервер через Accelerator / crash upload pipeline.

### Загрузка symbols

Серверная сторона Throttle умеет:

1. принимать готовые Breakpad `.sym` через `POST /symbols/submit`
2. генерировать Linux symbols локально из бинарников
3. переиндексировать наличие symbols
4. переобрабатывать старые крэши после загрузки symbols

Для `POST /symbols/submit` теперь требуется один из двух вариантов:

- авторизованный администратор
- заголовок `X-Symbol-Upload-Token: <token>` или параметр `token=<token>`

### Генерация Linux symbols

Если у вас есть точные бинарники нужной сборки:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml exec -T app php bin/console symbols:dump /path/to/binary
```

### Обновление индекса symbols

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml exec -T app php bin/console symbols:update
```

### Повторная обработка crash dump'ов

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml exec -T app php bin/console crash:process --reprocess
```

## 7. Важные замечания

### 7.1. MariaDB volume и смена пароля

Если вы уже один раз подняли `throttle-prod` и потом изменили `MARIADB_PASSWORD`, существующий volume базы данных сохранит старые credentials. В этом случае:

- либо оставляйте прежний пароль
- либо пересоздавайте volume базы данных

### 7.2. Что делает Accelerator

`Accelerator` в первую очередь отправляет dump и может отправлять symbols для загруженных в память модулей, если это разрешено настройками `MinidumpSymbolUpload` / `MinidumpBinaryUpload`. Но для стабильной production-схемы лучше всё равно хранить symbols отдельно на стороне Throttle и привязывать их к релизам.

### 7.3. Что лучше для Linux symbols

Лучший рабочий вариант:

1. на build-сервере сохранять точные ELF / `.so` каждой сборки
2. сразу генерировать Breakpad `.sym`
3. загружать их в Throttle до появления реальных крэшей

Тогда reprocess будет давать полноценную символизацию без ручного поиска бинарников задним числом.
