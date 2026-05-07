# Throttle

Throttle is a Symfony 8.0 crash-reporting service for SRCDS dumps with legacy crash processing, symbol storage, and web dashboard restored.

Русская инструкция по установке на production: [docs/PRODUCTION.ru.md](O:\GitHub\throttle\docs\PRODUCTION.ru.md)

## Production install

Requirements:

- Docker Engine / Docker Desktop with Compose
- A free TCP port for the web UI
- Matching symbol files or binaries for the dumps you want to symbolicate

1. Create a production env file from the example:

```bash
cp .env.prod.docker.example .env.prod.docker
```

2. Set at minimum:

- `APP_SECRET`
- `MARIADB_PASSWORD`
- `MARIADB_ROOT_PASSWORD`
- `STEAM_API_KEY` if Steam login is enabled
- OAuth client ids/secrets for any providers you want enabled

3. Start the stack:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml up -d --build
```

4. Check status:

```bash
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml ps
docker compose --env-file .env.prod.docker -p throttle-prod -f compose.prod.yaml logs -f app
```

The app will be available on `http://localhost:${APP_PORT}`. Database migrations run automatically by default through `AUTO_MIGRATE=1`.

If you change `MARIADB_PASSWORD` for an existing named MariaDB volume, the database container will keep the old credentials. In that case either keep the previous password or recreate the database volume for that project.

## Symbols

This project stores Breakpad symbol files under `/app/symbols/<store>/<module>/<identifier>/<module>.sym.gz`.

There are three ways to populate symbols:

1. Upload ready `.sym` files to `POST /symbols/submit`
2. Generate Linux symbol files locally with:

```bash
docker compose exec app php bin/console symbols:dump /path/to/binary [more binaries...]
```

3. Download Windows/Mozilla public symbols with:

```bash
docker compose exec app php bin/console symbols:download
docker compose exec app php bin/console symbols:mozilla:download
```

After adding symbols, refresh presence flags and reprocess crashes:

```bash
docker compose exec app php bin/console symbols:update
docker compose exec app php bin/console crash:process --reprocess
```

## Notes

- `accelerator` is the dump uploader on the game server side. Symbol upload is a separate server-side concern in Throttle.
- Linux crashes require exact matching binaries or `.sym` files for full stack symbolication.
