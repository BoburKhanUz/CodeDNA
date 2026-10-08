import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { metadata } from "@/app/invitations/accept/page";
import { InvitationAccept } from "@/components/organizations/invitation-accept";
import { pendingInvitation, rememberInvitation } from "@/lib/organizations/invitation-link";
import { me, ORG_ID, organization, preview, TOKEN } from "@/test/organizations";
import { apiErrorResponse, jsonResponse, user } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();

function serve({ previewResponse = () => jsonResponse({ data: preview() }), signedIn = true, accept = () => jsonResponse({ data: {
  type: "organization_invitation_acceptance", organization: organization({ role: "MEMBER" }), membership: me("MEMBER") } }) }: {
  previewResponse?: () => Response; signedIn?: boolean; accept?: () => Response;
} = {}) {
  fetchMock.mockImplementation(async (input, init) => {
    const url = String(input);
    if (url.endsWith("/api/v1/me")) return signedIn ? jsonResponse({ data: user }) : apiErrorResponse(401, "AUTHENTICATION_REQUIRED");
    if (url.endsWith("/accept") && init?.method === "POST") return accept();
    if (url.includes("/organizations/invitations/")) return previewResponse();
    throw new Error(`unexpected ${url}`);
  });
}

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  window.sessionStorage.clear();
  document.cookie = "XSRF-TOKEN=test-token";
});

afterEach(() => window.history.replaceState(null, "", "/"));

function open(hash: string) {
  window.history.replaceState(null, "", `/invitations/accept${hash}`);
  render(<InvitationAccept />);
}

describe("InvitationAccept", () => {
  it("takes the token from the fragment, removes it from the address bar and accepts", async () => {
    serve();
    open(`#${TOKEN}`);

    expect(await screen.findByRole("heading", { name: "Join Acme Engineering" })).toBeInTheDocument();
    expect(window.location.hash).toBe("");
    expect(screen.getByText(/i…@example.com/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Accept invitation" }));

    await waitFor(() => expect(router.replace).toHaveBeenCalledWith(`/app/organizations/${ORG_ID}`));
    expect(String(fetchMock.mock.calls.find(([, init]) => init?.method === "POST")?.[0])).toBe(`/api/v1/organizations/invitations/${TOKEN}/accept`);
    expect(pendingInvitation()).toBeNull();
  });

  it("keeps the default referrer policy so later API calls stay first-party", () => {
    // A no-referrer policy outlives client-side navigation, and Sanctum then
    // treats same-origin GET requests as stateless (401). The token is in the
    // fragment, which is never part of a Referer, so no policy is needed.
    expect(metadata).not.toHaveProperty("referrer");
  });

  it("asks a signed-out visitor to sign in and keeps the invitation for afterwards", async () => {
    serve({ signedIn: false });
    open(`#${TOKEN}`);

    expect(await screen.findByRole("link", { name: "Sign in" })).toHaveAttribute("href", "/login");
    expect(screen.getByRole("link", { name: "Create account" })).toHaveAttribute("href", "/register");
    expect(screen.queryByRole("button", { name: "Accept invitation" })).not.toBeInTheDocument();
    expect(pendingInvitation()).toBe(TOKEN);
  });

  it("continues a pending invitation after sign-in without a fragment", async () => {
    rememberInvitation(TOKEN);
    serve();
    open("");

    expect(await screen.findByRole("button", { name: "Accept invitation" })).toBeInTheDocument();
  });

  it("explains closed invitations without organization details", async () => {
    serve({ previewResponse: () => jsonResponse({ data: preview({ status: "EXPIRED", organization: null, role: null, expires_at: null, email_hint: null }) }) });
    open(`#${TOKEN}`);

    expect(await screen.findByTestId("invitation-closed")).toHaveTextContent("expired");
    expect(screen.queryByRole("button", { name: "Accept invitation" })).not.toBeInTheDocument();
  });

  it("shows unknown and malformed links as not found, without calling the API for malformed ones", async () => {
    serve({ previewResponse: () => apiErrorResponse(404, "RESOURCE_NOT_FOUND") });
    open(`#${TOKEN}`);
    expect(await screen.findByTestId("invitation-invalid")).toBeInTheDocument();
    expect(pendingInvitation()).toBeNull();
  });

  it("refuses a malformed token before any request", async () => {
    serve();
    open("#not-a-token");
    expect(await screen.findByTestId("invitation-invalid")).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("explains a refusal such as the wrong account or a full team", async () => {
    serve({ accept: () => apiErrorResponse(403, "INVITATION_EMAIL_MISMATCH") });
    open(`#${TOKEN}`);

    await userEvent.click(await screen.findByRole("button", { name: "Accept invitation" }));
    expect(await screen.findByText(/sent to a different email address/)).toBeInTheDocument();
    expect(router.replace).not.toHaveBeenCalled();
    expect(pendingInvitation()).toBe(TOKEN);
  });
});
