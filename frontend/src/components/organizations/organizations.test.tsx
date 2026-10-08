import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import type { ReactElement } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AuthProvider } from "@/components/auth/auth-provider";
import { OrganizationAnalytics } from "@/components/organizations/organization-analytics";
import { OrganizationAudit } from "@/components/organizations/organization-audit";
import { OrganizationList } from "@/components/organizations/organization-list";
import { OrganizationMembers } from "@/components/organizations/organization-members";
import { OrganizationOverview } from "@/components/organizations/organization-overview";
import { OrganizationProjects } from "@/components/organizations/organization-projects";
import type { Organization, OrganizationRole } from "@/lib/api/types";
import { analytics, auditEvent, invitation, me, membership, ORG_ID, organization, TOKEN } from "@/test/organizations";
import { apiErrorResponse, jsonResponse, page, project, user } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router, usePathname: () => "/app/organizations", useSearchParams: () => new URLSearchParams() };
});

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

type Route = [method: string, pattern: RegExp, respond: (init: RequestInit | undefined) => Response];

/** Answers each request from the first matching route; unknown requests fail the test. */
function serve(routes: Route[]) {
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    const method = init?.method ?? "GET";
    const route = routes.find(([m, pattern]) => m === method && pattern.test(url));
    if (!route) throw new Error(`unexpected ${method} ${url}`);
    return route[2](init);
  });
}

const orgRoute = (role: OrganizationRole, overrides: Partial<Organization> = {}): Route => [
  "GET",
  new RegExp(`/api/v1/organizations/${ORG_ID}$`),
  () => jsonResponse({ data: organization({ role, ...overrides }) }),
];

function requests(method: string, pattern: RegExp) {
  return fetchMock.mock.calls.filter(([url, init]) => (init?.method ?? "GET") === method && pattern.test(String(url)));
}

function signedIn(element: ReactElement) {
  return render(<AuthProvider user={user}>{element}</AuthProvider>);
}

describe("OrganizationList", () => {
  it("lists the user's teams with their role and counts", async () => {
    serve([["GET", /\/organizations\?/, () => jsonResponse(page([organization(), organization({ id: "01k6p0a1b2c3d4e5f6g7h8j9o2", name: "Beta", role: "MEMBER", membership_status: "SUSPENDED" })]))]]);
    render(<OrganizationList />);

    const list = await screen.findByTestId("organizations");
    expect(within(list).getByRole("link", { name: "Acme Engineering" })).toHaveAttribute("href", `/app/organizations/${ORG_ID}`);
    expect(within(list).getByText("Owner")).toBeInTheDocument();
    expect(within(list).getByText(/membership suspended/)).toBeInTheDocument();
    expect(within(list).queryByRole("link", { name: "Beta" })).not.toBeInTheDocument();
    expect(within(list).getAllByText("3 active members · 2 projects")).toHaveLength(2);
  });

  it("creates a team and opens it", async () => {
    serve([
      ["GET", /\/organizations\?/, () => jsonResponse(page([]))],
      ["POST", /\/organizations$/, () => jsonResponse({ data: organization() }, 201)],
    ]);
    render(<OrganizationList />);

    expect(await screen.findByTestId("organizations-empty")).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText("Team name"), "  Acme  ");
    await userEvent.click(screen.getByRole("button", { name: "Create team" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/app/organizations/${ORG_ID}`));
    expect(JSON.parse(String(requests("POST", /\/organizations$/)[0]?.[1]?.body))).toEqual({ name: "Acme" });
  });

  it("sends a signed-out user to sign in", async () => {
    serve([["GET", /\/organizations\?/, () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED")]]);
    render(<OrganizationList />);
    await waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});

describe("OrganizationOverview", () => {
  it("shows owners the plan, rename and archive controls", async () => {
    serve([
      orgRoute("OWNER"),
      ["GET", /\/billing$/, () => jsonResponse({ data: { type: "organization_billing", plan: { key: "FREE", version: "1.0.0", name: "Free" }, entitlement_version: "1.0.0",
        seats: { limit: 5, used: 3, remaining: 2 }, period: { start: "2026-10-01T00:00:00Z", end: "2026-11-01T00:00:00Z" }, quotas: [] } })],
    ]);
    render(<OrganizationOverview organizationId={ORG_ID} />);

    expect(await screen.findByText("Free plan · 3 of 5 seats used")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Save name" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Archive team…" })).toBeInTheDocument();
  });

  it("shows members the team without any settings or billing", async () => {
    serve([orgRoute("MEMBER")]);
    render(<OrganizationOverview organizationId={ORG_ID} />);

    expect(await screen.findByTestId("organization-summary")).toBeInTheDocument();
    expect(screen.queryByTestId("organization-billing")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Save name|Archive/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Audit log" })).not.toBeInTheDocument();
    expect(requests("GET", /\/billing$/)).toHaveLength(0);
  });

  it("marks an archived team read-only and offers no changes", async () => {
    serve([orgRoute("OWNER", { status: "ARCHIVED" }), ["GET", /\/billing$/, () => apiErrorResponse(500, "INTERNAL_ERROR")]]);
    render(<OrganizationOverview organizationId={ORG_ID} />);

    expect(await screen.findByTestId("organization-status")).toHaveTextContent("Archived: read-only");
    expect(screen.queryByRole("button", { name: /Save name|Archive/ })).not.toBeInTheDocument();
  });
});

describe("OrganizationMembers", () => {
  const owner = membership("OWNER");
  const admin = membership("ADMIN");
  const member = membership("MEMBER");

  function serveMembers(myRole: OrganizationRole, list = [owner, admin, member, me(myRole)]) {
    serve([
      orgRoute(myRole),
      ["GET", /\/members\?/, () => jsonResponse(page(list))],
      ["GET", /\/invitations\?/, () => jsonResponse(page([invitation()]))],
      ["PATCH", /\/members\//, () => jsonResponse({ data: member })],
      ["DELETE", /\/members\//, () => jsonResponse({ data: { ...member, status: "REMOVED" } })],
      ["POST", /\/invitations$/, () => jsonResponse({ data: { ...invitation({ email: "new@example.com" }), token: TOKEN } }, 201)],
      ["POST", /\/revoke$/, () => jsonResponse({ data: invitation({ status: "REVOKED" }) })],
    ]);
  }

  it("gives a member no controls and no invitations", async () => {
    serveMembers("MEMBER");
    signedIn(<OrganizationMembers organizationId={ORG_ID} />);

    await screen.findByTestId("members");
    expect(screen.queryByRole("button", { name: /Suspend|Remove/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
    expect(screen.queryByText("Invitations")).not.toBeInTheDocument();
    expect(requests("GET", /\/invitations\?/)).toHaveLength(0);
  });

  it("lets an admin manage members only, never the owner, another admin or themselves", async () => {
    serveMembers("ADMIN");
    signedIn(<OrganizationMembers organizationId={ORG_ID} />);

    const rows = await screen.findAllByTestId("member");
    const controls = rows.map((row) => within(row).queryByRole("button", { name: "Remove" }) !== null);
    expect(controls).toEqual([false, false, true, false]);
    // An admin can give a member at most their own role.
    const select = screen.getByRole("combobox", { name: `Role of ${member.user.name}` });
    expect(within(select).getAllByRole("option").map((o) => o.textContent)).toEqual(["Admin", "Member"]);
    await userEvent.selectOptions(select, "ADMIN");
    await waitFor(() => expect(requests("PATCH", new RegExp(`/members/${member.id}$`))).toHaveLength(1));
    expect(JSON.parse(String(requests("PATCH", /\/members\//)[0]?.[1]?.body))).toEqual({ role: "ADMIN" });
  });

  it("lets the owner suspend and remove admins", async () => {
    serveMembers("OWNER", [me("OWNER"), admin, member]);
    signedIn(<OrganizationMembers organizationId={ORG_ID} />);

    const rows = await screen.findAllByTestId("member");
    await userEvent.click(within(rows[1]!).getByRole("button", { name: "Suspend" }));
    await waitFor(() => expect(JSON.parse(String(requests("PATCH", /\/members\//)[0]?.[1]?.body))).toEqual({ status: "SUSPENDED" }));
    await userEvent.click(within(rows[1]!).getByRole("button", { name: "Remove" }));
    await waitFor(() => expect(requests("DELETE", new RegExp(`/members/${admin.id}$`))).toHaveLength(1));
  });

  it("explains a refused change with the server's error code", async () => {
    serve([
      orgRoute("OWNER"),
      ["GET", /\/members\?/, () => jsonResponse(page([me("OWNER"), membership("MEMBER", { status: "SUSPENDED" })]))],
      ["GET", /\/invitations\?/, () => jsonResponse(page([]))],
      ["PATCH", /\/members\//, () => apiErrorResponse(402, "SEAT_LIMIT_REACHED")],
    ]);
    signedIn(<OrganizationMembers organizationId={ORG_ID} />);

    await userEvent.click(await screen.findByRole("button", { name: "Reactivate" }));
    expect(await screen.findByText(/no free seat/)).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("server message");
  });

  it("creates an invitation, shows its link once and revokes pending ones", async () => {
    serveMembers("OWNER", [me("OWNER")]);
    signedIn(<OrganizationMembers organizationId={ORG_ID} />);

    await userEvent.type(await screen.findByLabelText("Email address"), "new@example.com");
    await userEvent.selectOptions(screen.getByLabelText("Role"), "ADMIN");
    await userEvent.click(screen.getByRole("button", { name: "Create invitation" }));

    const link = await screen.findByTestId("invitation-link");
    expect(link).toHaveTextContent(`/invitations/accept#${TOKEN}`);
    expect(JSON.parse(String(requests("POST", /\/invitations$/)[0]?.[1]?.body))).toEqual({ email: "new@example.com", role: "ADMIN" });
    await userEvent.click(within(link).getByRole("button", { name: "Done" }));
    expect(screen.queryByTestId("invitation-link")).not.toBeInTheDocument();
    expect(document.body).not.toHaveTextContent(TOKEN);

    await userEvent.click(within(screen.getByTestId("invitations")).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(requests("POST", /\/revoke$/)).toHaveLength(1));
  });
});

describe("OrganizationProjects", () => {
  const teamProject = { ...project, organization_id: ORG_ID };

  it("lists team projects linking to the ordinary project pages, and admins create more", async () => {
    serve([
      orgRoute("ADMIN"),
      ["GET", /\/organizations\/[^/]+\/projects\?/, () => jsonResponse(page([teamProject]))],
      ["POST", /\/organizations\/[^/]+\/projects$/, () => jsonResponse({ data: teamProject }, 201)],
    ]);
    render(<OrganizationProjects organizationId={ORG_ID} />);

    expect(await screen.findByRole("link", { name: project.name })).toHaveAttribute("href", `/app/projects/${project.id}`);
    await userEvent.type(screen.getByLabelText("Name"), "Team API");
    expect(screen.getByLabelText("Slug")).toHaveValue("team-api");
    await userEvent.click(screen.getByRole("button", { name: "Create team project" }));
    await waitFor(() => expect(router.push).toHaveBeenCalledWith(`/app/projects/${project.id}`));
    expect(JSON.parse(String(requests("POST", /\/projects$/)[0]?.[1]?.body))).toMatchObject({ name: "Team API", slug: "team-api", source_type: "UPLOAD" });
  });

  it("offers members no creation form and shows the empty state", async () => {
    serve([orgRoute("MEMBER"), ["GET", /\/projects\?/, () => jsonResponse(page([]))]]);
    render(<OrganizationProjects organizationId={ORG_ID} />);

    expect(await screen.findByTestId("organization-projects-empty")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Create team project" })).not.toBeInTheDocument();
  });

  it("shows the plan limit when the team has no project slot left", async () => {
    serve([
      orgRoute("OWNER"),
      ["GET", /\/projects\?/, () => jsonResponse(page([]))],
      ["POST", /\/projects$/, () => apiErrorResponse(402, "QUOTA_EXCEEDED")],
    ]);
    render(<OrganizationProjects organizationId={ORG_ID} />);

    await userEvent.type(await screen.findByLabelText("Name"), "Fourth");
    await userEvent.click(screen.getByRole("button", { name: "Create team project" }));
    expect(await screen.findByText(/reached your plan's limit/)).toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  });
});

describe("OrganizationAudit", () => {
  it("shows admins the timeline with safe descriptions", async () => {
    serve([
      orgRoute("ADMIN"),
      ["GET", /\/audit-events\?/, () => jsonResponse(page([
        auditEvent("MEMBER_ROLE_CHANGED", { metadata: { user_id: "x", role: { from: "MEMBER", to: "ADMIN" } } }),
        auditEvent("MEMBER_INVITED", { metadata: { email: "dev@example.com", role: "MEMBER" } }),
      ], { total: 30, last_page: 2 }))],
    ]);
    render(<OrganizationAudit organizationId={ORG_ID} />);

    const events = await screen.findByTestId("audit-events");
    expect(within(events).getByText("Role changed")).toBeInTheDocument();
    expect(events).toHaveTextContent("member → admin");
    expect(events).toHaveTextContent("dev@example.com");
    await userEvent.click(screen.getByRole("button", { name: "Older" }));
    await waitFor(() => expect(requests("GET", /\/audit-events\?page=2/)).toHaveLength(1));
  });

  it("does not ask the API for a member", async () => {
    serve([orgRoute("MEMBER")]);
    render(<OrganizationAudit organizationId={ORG_ID} />);

    expect(await screen.findByTestId("audit-forbidden")).toBeInTheDocument();
    expect(requests("GET", /\/audit-events/)).toHaveLength(0);
  });
});

describe("OrganizationAnalytics", () => {
  it("shows figures by version and says when evidence is thin", async () => {
    serve([orgRoute("MEMBER"), ["GET", /\/analytics$/, () => jsonResponse({ data: analytics() })]]);
    render(<OrganizationAnalytics organizationId={ORG_ID} />);

    expect(await screen.findByTestId("analytics-summary")).toHaveTextContent("Active members3");
    expect(screen.getByTestId("analytics-dna")).toHaveTextContent("Scoring 1.0.0: 2 projects · average overall score 61");
    const rows = screen.getAllByTestId("analytics-competency");
    expect(rows[0]).toHaveTextContent("COMPLEXITY_MANAGEMENT255");
    expect(rows[1]).toHaveAttribute("data-sufficient", "false");
    expect(rows[1]).toHaveTextContent("Not enough evidence");
    expect(screen.getByText(/Personal projects are never included/)).toBeInTheDocument();
  });

  it("explains an empty team", async () => {
    serve([
      orgRoute("OWNER"),
      ["GET", /\/analytics$/, () => jsonResponse({ data: analytics({ dna: { projects_with_dna: 0, members_with_dna: 0, by_version: [] }, competencies: [] }) })],
    ]);
    render(<OrganizationAnalytics organizationId={ORG_ID} />);

    expect(await screen.findByTestId("analytics-competencies-empty")).toBeInTheDocument();
    expect(screen.getByTestId("analytics-dna")).toHaveTextContent("No team project has been analysed yet.");
  });
});
