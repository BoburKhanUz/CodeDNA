# Licensing

The enterprise license (Phase 27): what it contains, how it is verified,
what it grants, and how keys and licenses are issued and rotated. Decision
record: [ADR-013](../decisions/ADR-013-enterprise-licensing.md).

## Principles

- **Offline.** A license is verified with an Ed25519 public key that ships
  in the release (`backend/config/license.php`). Nothing is fetched or
  reported over the network.
- **Public keys only.** The repository, the images, CI and every
  configuration example contain only public keys. The private signing key
  belongs to the issuer, offline, and never enters any of them.
- **Keys are code, not configuration.** No environment variable can add a
  trusted key: an operator-supplied key would let anyone sign their own
  license.
- **Fails closed.** Only a license that verifies completely grants anything.
  Every other outcome is the Community edition.
- **Never silent.** Every non-granting status is visible in:
  - `codedna:license`;
  - `codedna:preflight`;
  - `GET /api/v1/installation`;
  - the billing page;
  - the logs (`license.not_granting`).

## Format

A license document is a small JSON file:

```json
{
  "format": "codedna-license",
  "version": 1,
  "key_id": "<id of the signing key>",
  "payload": "<base64url of the claims JSON, exactly as signed>",
  "signature": "<base64url Ed25519 signature of the payload bytes>"
}
```

The signature covers the payload bytes exactly as transmitted, so no JSON
canonicalization is involved. The claims (schema `codedna.license.v1`):

```json
{
  "schema": "codedna.license.v1",
  "license_id": "lic-0001",
  "licensee": "Example Corp",
  "issued_at": "2026-01-01T00:00:00Z",
  "not_before": "2026-01-01T00:00:00Z",
  "expires_at": "2027-01-01T00:00:00Z",
  "installation": { "app_url_host": "codedna.example.com" },
  "entitlements": { "organization_plan": "TEAM_READY", "organization_seats": 500 }
}
```

The schema is strict:

- **Keys:** every key is required; unknown keys are refused, in any position.
- **Times:** whole-second UTC (`Z`).
- **Host:** `app_url_host` is the exact host of the installation's
  `APP_URL`, case-insensitive.
- **Plan:** `organization_plan` may only be `TEAM_READY` (the team plan
  reserved since Phase 23) in v1.
- **Seats:** `organization_seats` is an integer from 1 to 100,000.
- **Size:** files over 16 KiB are refused unread.

## Statuses

`App\Services\Enterprise\LicenseVerifier` checks in this order. It reads
the claims only after the signature verifies.

| Status | Meaning | Grants |
|---|---|---|
| `ABSENT` | No license configured, or an empty file (the production default mounts `/dev/null`) | no |
| `UNREADABLE` | The configured file is missing, unreadable or larger than 16 KiB | no |
| `MALFORMED` | Not a license envelope, or signed claims that break the schema | no |
| `UNSUPPORTED_VERSION` | An envelope version or claims schema this release does not know | no |
| `UNKNOWN_KEY` | Signed with a key this release does not trust | no |
| `INVALID_SIGNATURE` | Altered after signing, or not signed by the named key | no |
| `WRONG_INSTALLATION` | Issued for another `APP_URL` host | no |
| `NOT_YET_VALID` | Before `not_before` | no |
| `EXPIRED` | At or after `expires_at` (no grace period) | no |
| `VALID` | All of the above passed | **yes** |

## Entitlements

A valid license applies to **every organization** on the installation:

| Claim | Effect |
|---|---|
| `organization_plan` | Team projects are measured against this plan (all features, `TEAM_READY` quotas) instead of the organization's billing account plan |
| `organization_seats` | The seat limit becomes this value, or the account's limit when that is higher |

A license **never**:

- changes a user's personal plan;
- grants access to anything (authorization is unchanged);
- creates an administrator;
- crosses organization boundaries;
- is stored in the database.

## Install

1. Place the file on the host, outside the repository, for example
   `/etc/codedna/license.json`. It is not secret in the cryptographic sense:
   it is bound to your host and grants nothing elsewhere. Still, keep it
   `0644 root:root` or stricter, but readable by the container user.
2. Set `CODEDNA_LICENSE_FILE=/etc/codedna/license.json` in the environment
   file. It is mounted read-only into the backend, queue, scheduler and
   migration containers as the `codedna_license` secret.
3. Check the license, then apply it:

   ```bash
   docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env \
     run --rm --no-deps backend php artisan codedna:license
   docker compose -f docker-compose.prod.yml --env-file /etc/codedna/production.env up -d
   ```

   `codedna:license` exits non-zero when a license is configured but does
   not grant, so a renewal script can stop on a bad file.

**Renewal:** replace the file's contents and recreate the containers
(`up -d --force-recreate backend queue scheduler`). Each request and job
reads the license again, but a compose secret's source file is bind-mounted
when the container is created.

## Keys

`backend/config/license.php` maps key ids to base64 Ed25519 public keys:

```php
'trusted_keys' => [
    'codedna-2026-01' => '<base64 of the 32-byte public key>',
],
```

- **This release ships no issuing key.** Every license is `UNKNOWN_KEY` and
  every installation is the Community edition until the issuer publishes its
  first public key in a release. That is deliberate: a key generated by a
  development session would have its private half outside the issuer's
  control.
- **Generate a key pair offline** (on the issuer's side, never on a CodeDNA
  host). For example: `openssl genpkey -algorithm ed25519 -out issuer.pem`,
  then extract the raw 32-byte public key. Only the public key is committed.
- **Rotate a key** by:
  1. adding the new key in a release;
  2. issuing renewals with it;
  3. removing the old key in a later release, once every license signed
     with it has been renewed. Licenses signed by a removed key become
     `UNKNOWN_KEY`.
- **A compromised private key:** remove it in a patch release. Every license
  it signed stops granting once installations upgrade.

`ConfigurationValidator` refuses a malformed keyring at boot.

## Issuance

Issuing licenses is outside this repository by design. There is no license
generator, signing command or license server here. The tests sign their
fixtures with a throwaway key derived from a public seed
(`backend/tests/Support/LicenseFixtures.php`). Tests are not part of any
image, and that key is never trusted outside a test.

An issuer needs only:

- the format above;
- an Ed25519 implementation (libsodium `crypto_sign_detached`, OpenSSL,
  etc.);
- its private key.

## Limitations

- Licensing enforces a commercial agreement; it is not DRM. Anyone who can
  change the code on their own host can change what it does. The design
  protects against honest mistakes and casual reuse: a license is bound to
  one host and cannot be edited without the signature failing.
- Verification uses the host's clock. A host clock set far in the past or
  future changes `NOT_YET_VALID`/`EXPIRED` accordingly; keep NTP running.
- There is no grace period and no revocation list. Expiry is the only
  time limit, and key removal is the only revocation.
