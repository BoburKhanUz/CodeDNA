# Troubleshooting repository integrations

| Symptom | Cause | Fix |
|---|---|---|
| The page says "Not configured on this server" | The provider's client ID or secret is empty | Set both ([oauth-setup.md](oauth-setup.md)) and restart the backend and queue |
| The application refuses to start: "… configuration is incomplete" | Only one of ID and secret is set | Set both, or neither |
| "… must be on this installation's own origin (APP_URL)" | The callback URL points at another host | Use `https://<APP_URL host>/app/integrations/<provider>/callback`, and register the same URI with the provider |
| The provider shows "redirect URI mismatch" or "invalid redirect" | The registered redirect URI differs from `*_CALLBACK_URL` | Make them identical, including scheme and trailing path |
| `PROVIDER_STATE_INVALID` after authorizing | The state expired (10 min), was already used (back button or double submit), or belongs to another browser session or user | Start again from the project page |
| `PROVIDER_ACCOUNT_IN_USE` | That GitLab or Bitbucket account is linked to another CodeDNA user | That user disconnects it first (*Disconnect account*). Accounts are never moved automatically |
| `PROVIDER_AUTH_REQUIRED` on import or listing | The user has no linked account, or the provider refused the token or refresh (revoked, consumer deleted, password changed) | Click *Connect …* again |
| `PROVIDER_REPOSITORY_NOT_FOUND` | The user lost access, or the repository was deleted or moved | Restore access, or disconnect and connect another repository |
| `PROVIDER_BRANCH_NOT_FOUND` | The branch was deleted or renamed | Choose another branch on the project page |
| `PROVIDER_RATE_LIMITED` | The provider's rate limit was reached | Wait for the `Retry-After` time shown |
| `PROVIDER_UNAVAILABLE` | Network, DNS, TLS or a 5xx from the provider | Check outbound HTTPS from the backend and queue containers to the provider origin. For a self-managed GitLab, check its certificate chain is trusted |
| A Bitbucket import fails with `PROVIDER_IMPORT_FAILED` | The archive redirected to an origin not in `BITBUCKET_ARCHIVE_ORIGINS`, or did not return a ZIP | Check the queue log for `repository_provider` entries with `redirect_rejected` or `invalid_response`. If Bitbucket moved archive hosting, add that origin (origin only, https) |
| `SOURCE_ARCHIVE_TOO_LARGE`, `SOURCE_FILE_COUNT_EXCEEDED`, … | The repository exceeds the source limits | The limits are the same as for uploads (`SOURCE_*` settings) |
| `SOURCE_ALREADY_CONNECTED` | The project already has a GitHub, GitLab or Bitbucket source | Disconnect the current source first |
| `QUOTA_EXCEEDED` (`GITHUB_IMPORTS`) | The monthly repository import allowance (shared by all providers) is used up | Wait for the next period or upgrade the plan |
| Imports stay `QUEUED` | No worker consumes the `github` queue | Check that the queue service runs `--queue=…,github` (see `docker-compose*.yml`) |
| All users must reconnect after a deploy | `APP_KEY` changed, so stored tokens cannot be decrypted | Restore the key, or add the old one to `APP_PREVIOUS_KEYS` |

Logs never contain tokens, codes, state values or provider URLs with
credentials. Search for the `repository_provider.*` event names, which
include the provider, project, import and HTTP status.
