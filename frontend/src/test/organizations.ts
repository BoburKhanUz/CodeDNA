import type {
  InvitationPreview,
  Organization,
  OrganizationAnalytics,
  OrganizationAuditEvent,
  OrganizationInvitation,
  OrganizationMembership,
  OrganizationRole,
} from "@/lib/api/types";
import { user } from "@/test/responses";

export const ORG_ID = "01k6p0a1b2c3d4e5f6g7h8j9or";
export const TOKEN = "AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-abcde";

export function organization(overrides: Partial<Organization> = {}): Organization {
  return {
    id: ORG_ID,
    type: "organization",
    name: "Acme Engineering",
    slug: "acme-engineering-x1y2z3",
    status: "ACTIVE",
    role: "OWNER",
    membership_status: "ACTIVE",
    member_count: 3,
    project_count: 2,
    created_at: "2026-10-01T10:00:00Z",
    updated_at: "2026-10-01T10:00:00Z",
    ...overrides,
  };
}

let next = 0;
export function membership(role: OrganizationRole, overrides: Partial<OrganizationMembership> = {}): OrganizationMembership {
  next += 1;
  const id = `01k6p0a1b2c3d4e5f6g7h8m${String(next).padStart(3, "0")}`;
  return {
    id,
    type: "organization_membership",
    user: { id: `01k6p0a1b2c3d4e5f6g7h8u${String(next).padStart(3, "0")}`, name: `${role} person ${next}`, email: `${role.toLowerCase()}${next}@example.com` },
    role,
    status: "ACTIVE",
    joined_at: "2026-10-02T10:00:00Z",
    updated_at: "2026-10-02T10:00:00Z",
    ...overrides,
  };
}

/** The signed-in user (from @/test/responses) as a member with the given role. */
export function me(role: OrganizationRole): OrganizationMembership {
  return membership(role, { user: { id: user.id, name: user.name, email: user.email } });
}

export function invitation(overrides: Partial<OrganizationInvitation> = {}): OrganizationInvitation {
  return {
    id: "01k6p0a1b2c3d4e5f6g7h8i001",
    type: "organization_invitation",
    email: "invitee@example.com",
    role: "MEMBER",
    status: "PENDING",
    invited_by: { id: user.id, name: user.name },
    expires_at: "2026-10-05T10:00:00Z",
    accepted_at: null,
    revoked_at: null,
    created_at: "2026-10-02T10:00:00Z",
    ...overrides,
  };
}

export function preview(overrides: Partial<InvitationPreview> = {}): InvitationPreview {
  return {
    type: "organization_invitation_preview",
    status: "PENDING",
    organization: { name: "Acme Engineering" },
    role: "MEMBER",
    expires_at: "2026-10-05T10:00:00Z",
    email_hint: "i…@example.com",
    ...overrides,
  };
}

export function auditEvent(action: string, overrides: Partial<OrganizationAuditEvent> = {}): OrganizationAuditEvent {
  next += 1;
  return {
    id: `01k6p0a1b2c3d4e5f6g7h8a${String(next).padStart(3, "0")}`,
    type: "organization_audit_event",
    action,
    actor: { id: user.id, name: user.name },
    target: null,
    metadata: {},
    request_id: "11111111-2222-4333-8444-555555555555",
    created_at: "2026-10-03T10:00:00Z",
    ...overrides,
  };
}

export function analytics(overrides: Partial<OrganizationAnalytics> = {}): OrganizationAnalytics {
  return {
    type: "organization_analytics",
    organization_id: ORG_ID,
    generated_at: "2026-10-04T10:00:00Z",
    minimum_projects: 2,
    members: { active: 3, suspended: 0, by_role: { OWNER: 1, ADMIN: 1, MEMBER: 1 } },
    seats: { limit: 5, used: 3, remaining: 2 },
    projects: { active: 2, archived: 0 },
    analyses: { succeeded: 4, failed: 1, succeeded_last_30_days: 3, last_completed_at: "2026-10-03T09:00:00Z" },
    dna: {
      projects_with_dna: 2,
      members_with_dna: 2,
      by_version: [{ scoring_version: "1.0.0", projects: 2, scored_projects: 2, sufficient: true, average_overall_score: 0.6125 }],
    },
    competencies: [
      {
        competency_version: "1.0.0",
        skill_gap_version: "1.0.0",
        target_profile: "ENGINEERING_STANDARD",
        target_profile_version: "1.0.0",
        projects: 2,
        snapshot_status: { GAPS_IDENTIFIED: 2, NO_MATERIAL_GAPS: 0, INSUFFICIENT_DATA: 0 },
        competencies: [
          { key: "COMPLEXITY_MANAGEMENT", measured_projects: 2, sufficient: true, average_score: 0.55, gap_projects: 1, priorities: { HIGH: 1, MEDIUM: 0, LOW: 0 }, insufficient_evidence_projects: 0 },
          { key: "TYPE_STRUCTURE", measured_projects: 1, sufficient: false, average_score: null, gap_projects: 0, priorities: { HIGH: 0, MEDIUM: 0, LOW: 0 }, insufficient_evidence_projects: 1 },
        ],
      },
    ],
    ...overrides,
  };
}
