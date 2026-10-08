# Teams architecture (Phase 24)

Phase 24 adds organizations: tenant-level containers that own projects and
have members. The product calls them **teams**; the code, the API and the
database call them **organizations**.

```text
User ─┬─ Developer profile, personal projects, personal billing   (unchanged)
      └─ Memberships ──► Organization
                            ├─ Memberships (OWNER, ADMIN, MEMBER)
                            ├─ Invitations
                            ├─ Team projects ──► the ordinary project pipeline
                            ├─ Billing account (plan reference, seats)
                            ├─ Audit log
                            └─ Team analytics (read model)
```

**Individual DNA stays individual.** Joining, leaving or being removed from
a team changes no DNA, competency, skill gap, growth or history record.
Nothing is recalculated, re-scoped or copied. Team analytics read the team's
own project snapshots and never write.

Related documents:

- [Authorization](authorization.md): roles, the access checks and the
  project rules.
- [Invitations](invitations.md): tokens, acceptance and links.
- [Billing boundary](billing-boundary.md): the organization as a billing
  subject, and seats.
- [Team analytics](team-analytics.md): what is aggregated, and how.
- [API: Organizations](../api/README.md#organizations).

## Data model

Created by `2026_10_18_000001_create_organization_tables.php`. The migration
is additive and safe for existing data:

- existing projects keep `organization_id = NULL`, so they stay personal;
- users, billing, GitHub and history rows are unchanged.

The migration refuses to roll back once an organization exists, rather than
turning team projects into someone's personal projects.

| Table | Contents | Integrity |
|---|---|---|
| `organizations` | Owner, name, slug, status | Owner and slug immutable (trigger); status `ACTIVE`, `SUSPENDED` or `ARCHIVED` (CHECK); an archived organization stays archived; never deleted |
| `organization_memberships` | Organization, user, role, status, joined at | One row per organization and user (unique); at most one OWNER (partial unique); organization and user immutable; never deleted |
| `organization_invitations` | Email (canonical), role, token SHA-256, inviter, expiry, accepted or revoked | One open invitation per organization and email (partial unique); a decided invitation never changes (trigger) |
| `organization_audit_events` | Action, actor, target, metadata, request ID | Append-only (UPDATE refused by trigger, deletes by the model); indexed by organization timeline, actor and target |
| `organization_billing_accounts` | Plan version, entitlement version, seat limit | One per organization; no payment data |
| `billing_organization_usage_counters` | Monthly quota counters of an organization | `used >= 0` |
| `projects.organization_id` | Owning organization, or NULL | Immutable after creation (trigger); slugs unique per owner among personal projects, per organization among team projects |
| `billing_usage_events.organization_id` | The ledger's billing subject | Exactly one of `user_id` and `organization_id` (CHECK) |

**The owner invariant.** A deferred constraint trigger checks every
organization when a transaction commits:

- it has exactly one OWNER membership;
- that membership belongs to `owner_user_id` and is ACTIVE;
- the organization has a billing account.

The organization, its owner membership and its billing account are created
in one transaction. Nothing can then demote, suspend, remove or replace the
owner, even by direct SQL.

## Organization status

| Status | Reads | Changes |
|---|---|---|
| `ACTIVE` | Members | As roles allow |
| `SUSPENDED` | Members | None (`409 ORGANIZATION_SUSPENDED`). Set by the platform only; there is no API |
| `ARCHIVED` | Members | None (`409 ORGANIZATION_ARCHIVED`). Set by the owner (`POST .../archive`), irreversible |

"None" covers invitations, acceptances, membership changes, project creation,
project changes (including uploads, analyses and GitHub) and renaming.
Nothing is deleted or cascaded: projects, snapshots, runs, DNA,
competencies, skill gaps, growth, memberships and the audit log all remain.

## Creation

`POST /api/v1/organizations` with a name. In one transaction, `CreateOrganization`:

- creates the organization with a generated slug (a readable prefix from the name plus a random suffix);
- creates the creator's OWNER membership;
- creates the billing account (team entitlements 1.0.0);
- records `ORGANIZATION_CREATED`.

Because the slug is never chosen by the client, the API never reveals that
another organization's slug exists. A user may belong to any number of
organizations. Organization creation is rate-limited (5 per minute, 20 per
hour per user); there is no other limit.

## Projects

One ownership rule: `organization_id` decides.

- **Personal** (`organization_id` NULL): the owner (`user_id`) only, exactly as before.
- **Team**: the organization's.
  - `user_id` stays as the creator, for historical compatibility (the
    lineage foreign keys of DNA, competency and skill gap snapshots are
    keyed by it).
  - It grants nothing: access is decided by membership. A creator who leaves
    the team loses access to the projects they created.

Team projects are created with `POST /api/v1/organizations/{organization}/projects`
by the same `CreateProject` action as personal ones. After that, every
existing project route works for them, authorized by `ProjectPolicy`
([authorization](authorization.md#projects)).

- `GET /api/v1/projects` lists personal projects only.
- `POST /api/v1/projects` always creates a personal project, whatever the body contains.

Derived records (DNA, competencies, skill gaps, assessments, challenges,
roadmaps, growth, GitHub imports) are project-scoped. They work unchanged for
team projects and are shared by the team's members.

GitHub (Phase 19) keeps its boundaries:

- connecting, changing and importing require ADMIN or OWNER on a team project;
- the actor's own GitHub authorization and the installation checks still apply;
- tokens are never returned;
- there is no organization sync, webhook or pull request integration.

## Audit log

`OrganizationAudit` writes one entry inside the transaction of each change, so
an entry exists exactly when the change does. Actions are server-owned:

`ORGANIZATION_CREATED`, `ORGANIZATION_UPDATED`, `ORGANIZATION_ARCHIVED`,
`MEMBER_INVITED`, `MEMBER_JOINED`, `MEMBER_ROLE_CHANGED`, `MEMBER_SUSPENDED`,
`MEMBER_REACTIVATED`, `MEMBER_REMOVED`, `INVITATION_REVOKED`,
`PROJECT_CREATED`, `PROJECT_ARCHIVED`.

Each entry records:

- the actor;
- the target (`organization`, `membership`, `invitation` or `project`, with its ID);
- small metadata, such as an email, role or name, or a from/to change;
- the request ID.

Metadata keys that look like secrets (`token`, `secret`, `password`,
`session`, `source`, ...) are refused, so no token, credential or source code
can be logged by mistake. ADMINs and the OWNER read the log at
`GET .../audit-events`, newest first and paginated.

## Threat model

| Threat | Mitigation |
|---|---|
| Cross-organization IDOR | Membership checked first on every route (404 for outsiders, identical to a missing organization); scoped bindings for members and invitations; nested project resources bound to their project (`TeamProjectAccessTest`) |
| Organization enumeration | ULIDs; 404 before validation (`AuthorizesOrganizationView`); generated slugs (no "slug taken" answer); invitations never reveal whether an email has an account |
| Invitation token theft | 256-bit tokens, stored as SHA-256 only; bound to the invited email; 72-hour expiry; links carry the token in the URL fragment; access log redaction |
| Invitation replay | Single use, decided under row locks; decided invitations are immutable (trigger) |
| Invitation brute force | 256-bit search space; 404 for unknown and malformed tokens alike; rate limits per user and per IP |
| Role escalation, self-promotion | Roles come from the server's membership row only; actors act only on lower roles, never on themselves, and assign roles at most equal to their own; OWNER is never assignable |
| Removing or demoting the owner | Refused by the actions (`CANNOT_REMOVE_OWNER`, `CANNOT_CHANGE_OWNER_ROLE`) and by the database (deferred owner invariant, one-OWNER unique index) |
| Seat-limit races | Seat checked under the organization row lock in the activating transaction (concurrency tests) |
| Archived or suspended organization changes | Every change requires an ACTIVE organization (`requireForChange`), re-checked under the lock |
| Suspended or removed member access | Access needs an ACTIVE membership on every request; removal and suspension take effect at commit |
| Audit tampering | Append-only (trigger, model); no write route; secret-looking metadata keys refused |
| Project scope confusion | One rule (`organization_id`), immutable after creation (trigger); personal routes never create or list team projects |
| Personal and team quota confusion | One billing subject per project and per ledger entry (CHECK); separate counters ([billing boundary](billing-boundary.md)) |

## Observability

Structured log events carry IDs only, never tokens, secrets or source:

- `organization.created`, `organization.archived`, `organization.member_invited`,
  `organization.member_joined`, `organization.membership_changed`,
  `organization.member_removed`;
- `organization.seat_limit_reached`, `organization.invitation_email_mismatch`.

## Known limitations

- **No ownership transfer.** It needs a two-sided, transactional handover
  that is not built yet; the owner is fixed for the organization's life.
- **No hard deletion** of organizations; archiving is the end state.
- **No email delivery.** The inviter shares the link (shown once).
- **No team payments.** Organizations use the FREE plan's limits
  ([billing boundary](billing-boundary.md)). Each organization has its own
  FREE quotas; organization creation is rate-limited, but a determined user
  can still create several organizations.
- **No custom roles**, SSO, GitLab or Bitbucket, and no AI team insights.
- **Team analytics** are computed on request (bounded SQL aggregates). A
  stored team DNA snapshot is a Phase 25+ concern.
