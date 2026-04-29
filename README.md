# ismaail/scheduler

A lightweight Docker container scheduler. It listens for Docker container lifecycle events, reads scheduling labels, and writes to `/etc/crontab` — which [Supercronic](https://github.com/aptible/supercronic) picks up and executes automatically.

## How It Works

```
┌─────────────────────────────────────────────────────────────┐
│                       Host Machine                          │
│                                                             │
│   ┌─────────────────────┐     ┌──────────────────────────┐  │
│   │  ismaail/scheduler  │     │   Your App Container     │  │
│   │                     │     │                          │  │
│   │  PHP/Swoole         │     │  acme.enabled=true       │  │
│   │  ├ EventListener    │───▶│  acme.laravel.schedule   │  │
│   │  ├ LabelParser      │     │  acme.laravel.command    │  │
│   │  ├ CrontabWriter    │     │                          │  │
│   │  └ Supercronic ─────┼───▶│  docker exec <cmd>       │  │
│   └─────────────────────┘     └──────────────────────────┘  │
│              │                                              │
│   /var/run/docker.sock                                      │
└─────────────────────────────────────────────────────────────┘
```

1. **On startup** — scans all running containers with scheduler labels and writes `/etc/crontab`
2. **On container start** — detects labels and appends jobs to `/etc/crontab`
3. **On container stop/die** — removes the container's jobs from `/etc/crontab`
4. **Supercronic** — watches `/etc/crontab` for changes and executes jobs via `docker exec`

---

## Requirements

- Docker Engine 20+
- The scheduler container must have access to `/var/run/docker.sock`

---

## Quick Start

### 1. Add labels to your app container

```yaml
# docker-compose.yml
services:
  my_app:
    image: my-laravel-app
    labels:
      acme.enabled: "true"
      acme.laravel.schedule: "* * * * *"
      acme.laravel.command: "php artisan schedule:run"
```

### 2. Add the scheduler container

```yaml
  scheduler:
    image: ismaail/scheduler
    container_name: scheduler
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    restart: unless-stopped
```

That's it. The scheduler will detect `my_app` and run `php artisan schedule:run` inside it every minute.

---

## Label Format

| Label                  | Required | Description                           |
|------------------------|----------|---------------------------------------|
| `acme.enabled`         | ✅        | Must be `"true"` to enable scheduling |
| `acme.<name>.schedule` | ✅        | Cron schedule expression              |
| `acme.<name>.command`  | ✅        | Command to run inside the container   |

`<name>` is a unique identifier for the job — use any slug you like (`laravel`, `backup`, `cleanup`, etc.).

### Multiple jobs per container

A single container can define multiple jobs by using different `<name>` values:

```yaml
labels:
  acme.enabled: "true"
  #
  acme.laravel.schedule: "@every 1m"
  acme.laravel.command: "php artisan schedule:run"
  #
  acme.backup.schedule: "0 2 * * *"
  acme.backup.command: "php artisan backup:run"
  #
  acme.cleanup.schedule: "@daily"
  acme.cleanup.command: "php artisan cache:clear"
```

---

## Schedule Format

Standard 5-field cron expressions and common aliases are supported. The `@every` shorthand (Go-style) is also supported and converted automatically.

### Standard cron

```
* * * * *        every minute
*/5 * * * *      every 5 minutes
0 * * * *        every hour
0 2 * * *        every day at 2am
0 2 * * 1        every Monday at 2am
```

### Aliases

```
@hourly          every hour
@daily           every day at midnight
@weekly          every Sunday at midnight
@monthly         every 1st of month at midnight
@yearly          every 1st of January at midnight
```

### @every shorthand (auto-converted)

```
@every 1m        → * * * * *
@every 5m        → */5 * * * *
@every 30m       → */30 * * * *
@every 1h        → 0 * * * *
@every 6h        → 0 */6 * * *
@every 12h       → 0 */12 * * *
@every 1d        → 0 0 * * *
```

> **Note:** Seconds are not supported. Minimum interval is 1 minute.

---

## Full docker-compose.yml Example

```yaml
services:

  scheduler:
    image: ismaail/scheduler
    container_name: scheduler
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
    restart: unless-stopped

  billing_app:
    image: my-laravel-app
    container_name: billing_app
    labels:
      acme.enabled: "true"
      acme.laravel.schedule: "* * * * *"
      acme.laravel.command: "php artisan schedule:run"
      acme.backup.schedule: "0 2 * * *"
      acme.backup.command: "php artisan backup:run"

  notifications_app:
    image: my-laravel-app
    container_name: notifications_app
    labels:
      acme.enabled: "true"
      acme.laravel.schedule: "@every 5m"
      acme.laravel.command: "php artisan schedule:run"
```

---

## Dynamic Container Detection

The scheduler automatically handles containers that start or stop **after** the scheduler is running — no restart required.

| Event              | Action                                                      |
|--------------------|-------------------------------------------------------------|
| Container starts   | Labels are read and jobs are added to `/etc/crontab`        |
| Container stops    | All jobs for that container are removed from `/etc/crontab` |
| Container restarts | Jobs are removed on stop, re-added on start                 |

---

## How Jobs Are Identified

Each job is identified by a SHA-256 signature derived from:

```
containerId + containerName + jobName + command
```

The schedule is intentionally **excluded** from the signature — so changing only the schedule updates the existing crontab entry in place without creating a duplicate.

---

## Crontab Format

The scheduler writes to `/etc/crontab` inside the scheduler container in this format:

```crontab
# job:a3f1c2d4e5b6a7f8c9d0e1f2a3b4c5d6e7f8a9b0...
* * * * * docker exec <containerID> php artisan schedule:run

# job:b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3...
0 2 * * * docker exec <containerID> php artisan backup:run
```

---

## License

MIT
