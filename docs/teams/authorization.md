# Team authorization (Phase 24)

Every organization-scoped decision is made by
`App\Services\Organizations\OrganizationAccess`. It is used by the policies
(`OrganizationPolicy`, `ProjectPolicy`) and again by each action, under the
organization row lock. Controllers contain no role checks, and the frontend
only hides controls the server would refuse anyway.

## The access checks

Every organization-scoped operation answers, in this order:

| # | Question | Failure |
|---|---|---|
| 1 | Is the caller authenticated? | `401 AUTHENTICATION_REQUIRED` |
| 2 | Does the organization exist, and is the caller a member (not REMOVED)? | `404 RESOURCE_NOT_FOUND`, identical for both cases (no enumeration) |
| 3 | Is the membership ACTIVE? | `403 MEMBERSHIP_SUSPENDED` |
| 4 | Does the role allow it? | `403 INSUFFICIENT_ORGANIZATION_ROLE` |
| 5 | For a change, is the organization ACTIVE? | `409 ORGANIZATION_SUSPENDED` or `ORGANIZATION_ARCHIVED` |
| 6 | Does the target belong to this organization? | `404` (scoped route bindings, and the actions re-check) |

Only the server's membership row counts. A role, organization ID, membership
ID or user ID sent by the client never authorizes anything. Requests check
membership in `authorize()`, before validation, so a non-member gets 404
even for invalid input.

## Roles

Roles are ordered **OWNER > ADMIN > MEMBER**; custom roles are not supported.

| Capability | OWNER | ADMIN | MEMBER |
|---|---|---|---|
| See the organization, members (names, roles), projects, analytics | ✓ | ✓ | ✓ |
| Work on team projects (upload, analyze, assess, challenges, roadmaps) | ✓ | ✓ | ✓ |
| See member emails, invitations, the audit log and billing context | ✓ | ✓ | – |
| Rename the organization | ✓ | ✓ | – |
| Create team projects; update, archive or connect GitHub to them | ✓ | ✓ | – |
| Invite (as ADMIN or MEMBER) and revoke invitations | ✓ | ✓ | – |
| Change, suspend, reactivate or remove members | ✓ (ADMINs and MEMBERs) | ✓ (MEMBERs only) | – |
| Archive the organization | ✓ | – | – |

## Membership changes

`ChangeMembership` (PATCH) and `RemoveMember` (DELETE):

- The actor must **outrank** the target (strictly higher role) and may never
  act on themselves. An ADMIN cannot demote, suspend or remove another
  ADMIN.
- A role can be given only **up to the actor's own**, and never OWNER (422).
  An ADMIN may promote a MEMBER to ADMIN; only the OWNER may demote an ADMIN.
- The OWNER membership is never demoted or suspended
  (`409 CANNOT_CHANGE_OWNER_ROLE`) and never removed
  (`409 CANNOT_REMOVE_OWNER`). The database enforces the same rule.
- Reactivating a SUSPENDED member takes a seat (`402 SEAT_LIMIT_REACHED`).
- DELETE sets REMOVED; the row and its history stay. A removed membership is
  `404` to this API; the person can only come back through a new invitation.
- Suspension and removal take effect with the transaction: the next request
  is refused.

## Projects

`ProjectPolicy` decides every project route:

| Project | view | contribute (upload, analyze, assess, practice, plan) | manage (update, archive, connect source) |
|---|---|---|---|
| Personal | owner | owner | owner |
| Team | ACTIVE member; any organization status | ACTIVE member; ACTIVE organization | ADMIN or OWNER; ACTIVE organization |

Non-owners of personal projects and non-members of team projects get the
same `404` as for a missing project. This covers the creator of a team
project once they are removed. Nested resources (snapshots, runs, challenges,
...) are bound to their project, so they are never reachable through another
organization's project.

## Tests

The rules are covered by these tests:

- `tests/Feature/Organizations/TeamProjectAccessTest.php` runs every
  project-scoped GET route for team projects. It covers members, strangers,
  members of another organization, removed and suspended members, and
  cross-organization nesting.
- `TeamProjectTest`, `OrganizationMembershipTest` and
  `OrganizationInvitationTest` cover the role matrix, owner protection and
  role-escalation attempts.
