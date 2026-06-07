# Clean WordPress Docker

This repository runs a clean WordPress site with official Docker images.

## Services

- `wordpress:latest`
- `mysql:8.0`

## Quick start

```bash
cp .env.example .env
docker compose up -d
```

Then open [http://localhost:8080](http://localhost:8080).

## Stop

```bash
docker compose down
```

## Remove all container data

```bash
docker compose down -v
```
