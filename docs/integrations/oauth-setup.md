# OAuth setup for GitLab and Bitbucket Cloud

Phase 28. Each provider is **off** until both its client ID and secret are
set. Setting only one is a configuration error: the boot validator
(`App\Support\ConfigurationValidator`) reports it, and the application
refuses to start in production. For GitHub, see
[github-integration-v1.md](../architecture/github-integration-v1.md).

## GitLab

1. On GitLab.com, or your self-managed instance, open *User settings → Applications*, or
   *Admin → Applications* for an instance-wide application. Create an
   application with:
   - **Redirect URI:** `https://<your-domain>/app/integrations/gitlab/callback`
   - **Confidential:** yes
   - **Scopes:** `read_api` only
2. Set the following variables:

```dotenv
GITLAB_CLIENT_ID=<Application ID>
GITLAB_CLIENT_SECRET=<Secret>            # secret: keep it in your secret store
GITLAB_BASE_URL=https://gitlab.com       # or https://git.example.com
# GITLAB_CALLBACK_URL defaults to APP_URL + /app/integrations/gitlab/callback
```

## Bitbucket Cloud

1. In a Bitbucket workspace, open *Settings → OAuth consumers → Add
   consumer* and set:
   - **Callback URL:** `https://<your-domain>/app/integrations/bitbucket/callback`
   - **This is a private consumer:** yes (needed for refresh tokens)
   - **Permissions:** Account: *Read*, Repositories: *Read*. Nothing else.
2. Set the following variables:

```dotenv
BITBUCKET_CLIENT_ID=<Key>
BITBUCKET_CLIENT_SECRET=<Secret>         # secret
# BITBUCKET_CALLBACK_URL defaults to APP_URL + /app/integrations/bitbucket/callback
# BITBUCKET_ARCHIVE_ORIGINS=https://bitbucket.org   (origins only, comma-separated)
```

`docker-compose.prod.yml` fixes the Bitbucket API and web URLs and derives
both callback URLs from `CODEDNA_DOMAIN`.

## What the validator enforces

- **Credentials:** the client ID and secret are set together, or neither is.
- **Provider URLs:** absolute, with no query or fragment, and `https` in
  every deployed environment.
- **Callback URL:** on this installation's own origin (APP_URL) when
  deployed, with the exact path `/app/integrations/<provider>/callback`.
- **Bitbucket archive origins:** at least one, each `scheme://host[:port]`
  with no path, `https` when deployed.

## The flow

1. The project page calls `POST /api/v1/repository-providers/{provider}/authorizations`
   with an optional `project_id`, which needs the `connectSource` ability.
   The server does the following:
   - creates 32 random bytes of state, base64url-encoded (43 characters);
   - stores only its **SHA-256 hash**, bound to the user, the provider and
     the optional project, with an expiry of 10 minutes by default and one
     hour at most;
   - returns the provider's authorize URL, which the browser follows. The
     frontend refuses any URL that is not http(s).
2. The provider redirects to `/app/integrations/{provider}/callback?code=…&state=…`.
   The page sends the code and state once to
   `POST /api/v1/repository-providers/{provider}/callback`, which requires
   a session.
3. The server consumes the state with a single conditional update:

   ```sql
   UPDATE … SET consumed_at = now()
   WHERE hash = ? AND user_id = ? AND provider = ? AND consumed_at IS NULL AND expires_at > now()
   ```

   A missing, expired, replayed, foreign-user or wrong-provider state
   answers `422 PROVIDER_STATE_INVALID`. A foreign user's or wrong
   provider's state is **not** consumed, so the rightful flow can still
   finish. Two concurrent callbacks with one state link once.
4. The code is exchanged for tokens. A code the provider refuses answers
   `409 PROVIDER_AUTH_REQUIRED`, and its state stays spent. The identity is
   read and validated.
5. If that provider identity is already linked to **another** CodeDNA user,
   the new token is revoked where possible and the request answers `409
   PROVIDER_ACCOUNT_IN_USE`. An account is never moved or merged.
   Otherwise the account row is created or updated under a lock.
6. The response is `{connected: true, project_id}`. `project_id` is
   returned only if the user may still connect that project.

## Token handling

- **At rest:** encrypted with Laravel's `encrypted` cast (AES-256-CBC with
  a MAC, keyed by `APP_KEY`). Rotating `APP_KEY` without
  `APP_PREVIOUS_KEYS` makes stored tokens unreadable, and users must
  connect again.
- **Where tokens never go:** never in an API response, the frontend, a
  queue payload (jobs carry the import ID only), a cache, a log line or an
  exception message. `ProviderTokens` redacts itself in `var_dump` and
  `print_r`, and token parameters are `#[SensitiveParameter]`.
- **Callback codes in logs:** the callback pages receive the code and
  state in their query string.
  - Nginx logs them as `?[redacted]` (development) or not at all
    (production, which logs paths only).
  - The Next.js development server cannot redact its request log, so
    `next.config.ts` leaves the three callback routes out of it
    (`logging.incomingRequests.ignore`). Every other request is still
    logged.
  - `make verify` checks both logs.
- **Whose token is used:** every repository call uses the **calling
  user's** token. An import uses the token of the user who requested it
  (`requested_by`), never the token of whoever first connected the
  repository.
- **Disconnecting an account** (`DELETE /api/v1/repository-providers/{provider}`):
  CodeDNA revokes the token where the provider supports it (GitLab), then
  **always** deletes the stored tokens, even when revocation fails or is
  not supported (Bitbucket). The response reports `revocation`: `REVOKED`,
  `NOT_SUPPORTED`, `FAILED` or `NOT_LINKED`.
