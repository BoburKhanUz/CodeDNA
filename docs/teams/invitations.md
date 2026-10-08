# Invitations (Phase 24)

An ADMIN or the OWNER invites an email address with a role (ADMIN or MEMBER,
never above their own). The invited person joins by accepting the
invitation while signed in with that email address. There is no email
delivery yet: the inviter shares the link.

## Tokens

- **Strength:** 32 bytes from the CSPRNG (256 bits), base64url, 43 characters.
- **Storage:** only the SHA-256 of the token (`organization_invitations.token_hash`,
  unique) is stored. The token is looked up by its hash, so the stored value
  is never compared with client input. The raw token is returned once, by
  `POST .../invitations`. No list, audit entry, log line or other response
  ever contains it or its hash.
- **Single use:** an accepted invitation can never be used again
  (`409 INVITATION_ALREADY_ACCEPTED`). A decided invitation never changes,
  which a trigger enforces.
- **Short-lived:** invitations expire 72 hours after creation
  (`409 INVITATION_EXPIRED`).
- **Revocable:** revoked invitations answer `409 INVITATION_REVOKED`. A new
  invitation to the same address revokes and replaces the open one.
- **Unknown tokens:** an unknown or malformed token answers `404`. Both
  invitation endpoints are rate-limited (accept: 10 per minute per user and
  20 per minute per IP; preview: 30 per minute per IP). Together with the
  token's 256 bits, this makes guessing hopeless.

## Links

The link is `https://<host>/invitations/accept#<token>`. The token is in the
**URL fragment**, which browsers never send to a server, so it reaches no
access log and no `Referer` header. The page:

- removes the fragment from the address bar at once;
- keeps the token in the tab's `sessionStorage` while the visitor signs in or
  registers (login and registration then return to the invitation);
- forgets the token once it is used.

The API endpoints have the token in their path
(`/api/v1/organizations/invitations/{token}[/accept]`). The Nginx access log
replaces it with `[redacted]` (`docker/nginx/conf.d/default.conf`; checked by
`make verify`). PHP-FPM keeps no access log, and Laravel logs no request
paths.

## Preview

`GET /api/v1/organizations/invitations/{token}` needs no session. While the
invitation is pending, it shows:

- the organization's name;
- the role;
- the expiry;
- a masked email (`j…@example.com`), so the visitor knows which account to use.

For a closed invitation it shows only the status. Nothing else about the
organization is shown, and nothing at all for an unknown token.

## Acceptance

`POST /api/v1/organizations/invitations/{token}/accept`, signed in. In one
transaction, with the organization and invitation rows locked:

1. The token must name a stored invitation (`404`).
2. The user's email, in canonical form (trimmed, lowercase), must equal the
   invitation's (`403 INVITATION_EMAIL_MISMATCH`). A stolen link is useless
   without the invited account. A mismatch leaves the invitation open.
3. The invitation must be open: not accepted, revoked or expired.
4. The organization must be ACTIVE.
5. An ACTIVE member gets `409 ALREADY_A_MEMBER`. A SUSPENDED member gets
   `403 MEMBERSHIP_SUSPENDED`, because an invitation never lifts a suspension.
6. A seat must be free (`402 SEAT_LIMIT_REACHED`). The invitation stays open
   and can be accepted once a seat is freed.
7. The membership becomes ACTIVE with the invited role. A REMOVED member's
   row is reused, since there is one row per organization and user. The
   invitation is marked accepted, and `MEMBER_JOINED` is recorded.

The user gets no access to the organization before this commits. Concurrent
acceptances are decided one at a time by the organization row lock: of two
acceptances racing for the last seat, exactly one succeeds
(`OrganizationConcurrencyTest`).

## Privacy

Creating an invitation never checks whether the email belongs to a CodeDNA
account, so the API does not reveal who has one. Only current (ACTIVE or
SUSPENDED) members of the same organization are refused
(`409 ALREADY_A_MEMBER`), and only to that organization's admins.
