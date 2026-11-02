# nuvori/aom-client

Reports errors, health, versions and feedback from a Laravel app to AOM. Supports PHP 8.2+ and Laravel 12 and 13.

```bash
composer require nuvori/aom-client
```

Set in `.env`:

```
AOM_URL=https://aom.nuvori.nl
AOM_INSTALL_TOKEN=aom_ins_...
```

Without these the package does nothing.

## Health check

`GET /aom/health` returns `200` with `{"status":"ok"}`, or `503` with the checks that fail: database, cache, queue worker, scheduler, disk space (≥ 5 % free). Let Docker run it, so the AOM agent sees the result:

```yaml
services:
  app:
    healthcheck:
      test: ["CMD", "php", "-r", "exit(@file_get_contents('http://localhost:8000/aom/health') === false ? 1 : 0);"]
      interval: 60s
      timeout: 10s
      retries: 2
```

Use the port your app listens on inside the container.
