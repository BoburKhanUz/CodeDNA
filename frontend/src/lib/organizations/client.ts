import { api } from "@/lib/api/client";
import type {
  CreateProjectRequest,
  CreatedInvitation,
  DataEnvelope,
  InvitationAcceptance,
  InvitationPreview,
  Organization,
  OrganizationAnalytics,
  OrganizationAuditEvent,
  OrganizationBilling,
  OrganizationInvitation,
  OrganizationMembership,
  OrganizationRole,
  Paginated,
  Project,
} from "@/lib/api/types";

/**
 * Organizations (Phase 24). Every call is authorized by the server; IDs are
 * ULIDs and invitation tokens 43 base64url characters, and anything else is
 * refused before a URL is built.
 */
const ORGANIZATIONS = "/api/v1/organizations";

export function isOrganizationId(value: string): boolean {
  return /^[0-9a-z]{26}$/i.test(value);
}

export function isInvitationToken(value: string): boolean {
  return /^[A-Za-z0-9_-]{43}$/.test(value);
}

function path(organizationId: string, suffix = ""): string {
  if (!isOrganizationId(organizationId)) {
    throw new Error("Invalid organization ID.");
  }
  return `${ORGANIZATIONS}/${organizationId.toLowerCase()}${suffix}`;
}

function childPath(organizationId: string, collection: string, id: string, suffix = ""): string {
  if (!isOrganizationId(id)) {
    throw new Error("Invalid ID.");
  }
  return path(organizationId, `/${collection}/${id.toLowerCase()}${suffix}`);
}

function tokenPath(token: string, suffix = ""): string {
  if (!isInvitationToken(token)) {
    throw new Error("Invalid invitation link.");
  }
  return `${ORGANIZATIONS}/invitations/${token}${suffix}`;
}

const page = (n: number, perPage: number) => `?page=${n}&per_page=${perPage}`;

export function listOrganizations(n = 1, perPage = 50): Promise<Paginated<Organization>> {
  return api.get<Paginated<Organization>>(`${ORGANIZATIONS}${page(n, perPage)}`);
}

export async function createOrganization(name: string): Promise<Organization> {
  return (await api.post<DataEnvelope<Organization>>(ORGANIZATIONS, { name })).data;
}

export async function getOrganization(organizationId: string): Promise<Organization> {
  return (await api.get<DataEnvelope<Organization>>(path(organizationId))).data;
}

export async function renameOrganization(organizationId: string, name: string): Promise<Organization> {
  return (await api.patch<DataEnvelope<Organization>>(path(organizationId), { name })).data;
}

/** OWNER only; irreversible. Nothing is deleted. */
export async function archiveOrganization(organizationId: string): Promise<Organization> {
  return (await api.post<DataEnvelope<Organization>>(path(organizationId, "/archive"))).data;
}

export async function getOrganizationBilling(organizationId: string): Promise<OrganizationBilling> {
  return (await api.get<DataEnvelope<OrganizationBilling>>(path(organizationId, "/billing"))).data;
}

export function listMembers(organizationId: string, n = 1, perPage = 100): Promise<Paginated<OrganizationMembership>> {
  return api.get<Paginated<OrganizationMembership>>(path(organizationId, `/members${page(n, perPage)}`));
}

export async function updateMember(
  organizationId: string,
  membershipId: string,
  change: { role?: Exclude<OrganizationRole, "OWNER">; status?: "ACTIVE" | "SUSPENDED" },
): Promise<OrganizationMembership> {
  return (await api.patch<DataEnvelope<OrganizationMembership>>(childPath(organizationId, "members", membershipId), change)).data;
}

/** The membership becomes REMOVED; it is never deleted. */
export async function removeMember(organizationId: string, membershipId: string): Promise<OrganizationMembership> {
  return (await api.delete<DataEnvelope<OrganizationMembership>>(childPath(organizationId, "members", membershipId))).data;
}

export function listInvitations(organizationId: string, n = 1, perPage = 50): Promise<Paginated<OrganizationInvitation>> {
  return api.get<Paginated<OrganizationInvitation>>(path(organizationId, `/invitations${page(n, perPage)}`));
}

export async function inviteMember(organizationId: string, email: string, role: Exclude<OrganizationRole, "OWNER">): Promise<CreatedInvitation> {
  return (await api.post<DataEnvelope<CreatedInvitation>>(path(organizationId, "/invitations"), { email, role })).data;
}

export async function revokeInvitation(organizationId: string, invitationId: string): Promise<OrganizationInvitation> {
  return (await api.post<DataEnvelope<OrganizationInvitation>>(childPath(organizationId, "invitations", invitationId, "/revoke"))).data;
}

export async function previewInvitation(token: string): Promise<InvitationPreview> {
  return (await api.get<DataEnvelope<InvitationPreview>>(tokenPath(token))).data;
}

export async function acceptInvitation(token: string): Promise<InvitationAcceptance> {
  return (await api.post<DataEnvelope<InvitationAcceptance>>(tokenPath(token, "/accept"))).data;
}

export function listOrganizationProjects(organizationId: string, n = 1, perPage = 25): Promise<Paginated<Project>> {
  return api.get<Paginated<Project>>(path(organizationId, `/projects${page(n, perPage)}`));
}

export async function createOrganizationProject(organizationId: string, input: CreateProjectRequest): Promise<Project> {
  return (await api.post<DataEnvelope<Project>>(path(organizationId, "/projects"), input)).data;
}

export function listAuditEvents(organizationId: string, n = 1, perPage = 25): Promise<Paginated<OrganizationAuditEvent>> {
  return api.get<Paginated<OrganizationAuditEvent>>(path(organizationId, `/audit-events${page(n, perPage)}`));
}

export async function getAnalytics(organizationId: string): Promise<OrganizationAnalytics> {
  return (await api.get<DataEnvelope<OrganizationAnalytics>>(path(organizationId, "/analytics"))).data;
}

const ROLE_RANK: Record<OrganizationRole, number> = { OWNER: 3, ADMIN: 2, MEMBER: 1 };

/**
 * Mirrors the server's rule (docs/teams/authorization.md) only to decide
 * which controls to show: an actor acts on members strictly below their own
 * role, never on themselves, and gives roles up to their own, never OWNER.
 */
export function canManage(actor: OrganizationRole, target: OrganizationMembership, self: boolean): boolean {
  return !self && target.role !== "OWNER" && ROLE_RANK[actor] > ROLE_RANK[target.role] && ROLE_RANK[actor] >= ROLE_RANK.ADMIN;
}

export function assignableRoles(actor: OrganizationRole): Exclude<OrganizationRole, "OWNER">[] {
  return (["ADMIN", "MEMBER"] as const).filter((role) => ROLE_RANK[actor] >= ROLE_RANK[role] && ROLE_RANK[actor] >= ROLE_RANK.ADMIN);
}

export function isAdmin(role: OrganizationRole): boolean {
  return ROLE_RANK[role] >= ROLE_RANK.ADMIN;
}

export const ROLE_LABELS: Record<OrganizationRole, string> = { OWNER: "Owner", ADMIN: "Admin", MEMBER: "Member" };
