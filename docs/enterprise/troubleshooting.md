# Troubleshooting (self-hosted)

Common problems on a self-hosted installation, in the order they usually
appear. `$COMPOSE` is your compose command with its overlays
([installation](self-hosted-installation.md#procedure)).

## A container stops at start with "invalid configuration"

The backend validates its configuration in every container before doing
anything. The error lists each problem by variable name. It never prints a
value.

```bash
$COMPOSE logs backend | grep -A20 -i "configuration"
```

| Message | Fix |
|---|---|
| `DB_SSLMODE must be "require", "verify-ca" or "verify-full" for a database outside the private network` | Enable TLS on the database and set `DB_SSLMODE=verify-full` |
| `REDIS_SCHEME must be "tls" for a Redis outside the private network` | Enable TLS on Redis and set `REDIS_SCHEME=tls` |
| `CODEDNA_LICENSE_PATH does not name a readable file` | The host file in `CODEDNA_LICENSE_FILE` is missing or not readable by the container user: check the path and `chmod 0644` |
| `REGISTRATION_ALLOWED_EMAIL_DOMAINS must list at least one domain…` | Add domains, or choose another `REGISTRATION_MODE` |
| `REGISTRATION_ALLOWED_EMAIL_DOMAINS is set but REGISTRATION_MODE is not "restricted"` | Set `REGISTRATION_MODE=restricted`, or clear the domains |
| Anything else | See [production configuration](../operations/production-configuration.md#what-production-refuses-at-boot) |

## Preflight

`codedna:preflight` reports `FAIL` per dependency. Details go to the log
as the exception class only (`preflight.failed`). The message never
includes hosts or credentials, which is why the table says only `FAIL`.

| Check | Usual causes |
|---|---|
| `database` | Wrong host or port; the database is not reachable from the container (with a customer-run database, check the overlay is in `$COMPOSE` and the firewall allows the route); TLS mode not supported by the server; wrong credentials |
| `migrations` | `not installed` or `N pending`: run the migration job. Expected before the first migration |
| `redis` / `redis (cache)` | Wrong host or password; TLS required by the server but `REDIS_SCHEME` empty (or the reverse); the certificate is not trusted by the image's CA store |
| `object storage` | Wrong endpoint, region, bucket or credentials; path-style setting wrong for the provider; no route from the backend |

Run it in the backend container: it has the routes to every data service.

```bash
$COMPOSE run --rm --no-deps backend php artisan codedna:preflight
```

## License

```bash
$COMPOSE run --rm --no-deps backend php artisan codedna:license
```

| Status | What to do |
|---|---|
| `ABSENT` | No license configured. That is the Community edition; set `CODEDNA_LICENSE_FILE` to install one |
| `UNREADABLE` | The mounted file cannot be read or is larger than 16 KiB. Check the host file and that containers were recreated after changing it |
| `MALFORMED` | The file is not a license, or was changed (whitespace inside the payload included). Use the file exactly as issued |
| `UNSUPPORTED_VERSION` | The license is newer than this release. Upgrade, or ask the issuer for a license this release supports |
| `UNKNOWN_KEY` | This release does not trust the license's signing key: an older release, or a key not published yet. Upgrade, or ask the issuer |
| `INVALID_SIGNATURE` | The license was altered or not signed by the issuer. Request a new copy |
| `WRONG_INSTALLATION` | The license is for another `APP_URL` host. `CODEDNA_DOMAIN` must match the host it was issued for |
| `NOT_YET_VALID` / `EXPIRED` | Outside its validity period. Check the host clock (NTP), then renew |

After replacing the file, recreate the containers that mount it:

```bash
$COMPOSE up -d --force-recreate backend queue scheduler
```

Teams return to their account's plan whenever the license does not grant.
Nothing else changes.

## Registration

- **"New accounts cannot be created on this installation"** means
  `REGISTRATION_MODE=closed`. Open it temporarily to add accounts.
- **"Registration on this installation is limited to approved email
  domains"** means the address's domain is not listed exactly. Subdomains
  must be listed on their own.
- **Changes do not apply:** after editing the environment file, recreate
  the backend (`up -d backend`). The configuration is cached at container
  start.

## Analyses stay queued with external storage

The analyzer downloads sources through pre-signed URLs. Check, in order:

1. `compose.external-storage.yml` is in `$COMPOSE`;
2. `ANALYZER_ALLOWED_SOURCE_HOSTS` is exactly the storage endpoint's host;
3. `ANALYZER_LOCAL_SOURCE_HOSTS` is empty;
4. the firewall allows the analyzer's route.

The failed run's `failure_code` and the analyzer log name the stage.

## Anything else

- [Production deployment](../operations/production-deployment.md#operating-notes)
- [Scaling guide](../performance/scaling-guide.md)
- `GET /api/v1/health` (database and Redis) and `GET /up` (liveness)
