import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { InstallationCard } from "@/components/billing/installation-card";
import type { Installation } from "@/lib/api/types";
import { apiErrorResponse, jsonResponse } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

function installation(overrides: Partial<Installation> = {}, license: Partial<Installation["license"]> = {}): Installation {
  return {
    type: "installation",
    edition: "COMMUNITY",
    registration: { mode: "open" },
    ...overrides,
    license: { status: "ABSENT", licensee: null, expires_at: null, organization_plan: null, organization_seats: null, ...license },
  };
}

describe("InstallationCard", () => {
  it("shows the Community edition by default", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: installation() }));
    render(<InstallationCard />);

    expect(await screen.findByRole("heading", { name: "Community edition" })).toBeInTheDocument();
    expect(screen.getByTestId("installation-community")).toHaveTextContent("own plan and seat limit");
    expect(fetchMock.mock.calls[0]![0]).toContain("/api/v1/installation");
  });

  it("shows the licensee, validity and team entitlement of a valid license", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: installation(
          { edition: "ENTERPRISE" },
          { status: "VALID", licensee: "Example Corp", expires_at: "2027-01-01T00:00:00Z", organization_plan: "TEAM_READY", organization_seats: 50 },
        ),
      }),
    );
    render(<InstallationCard />);

    expect(await screen.findByRole("heading", { name: "Enterprise edition" })).toBeInTheDocument();
    expect(screen.getByTestId("installation-license")).toHaveTextContent("Licensed to Example Corp");
    expect(screen.getByTestId("installation-license")).toHaveTextContent("up to 50 seats");
    expect(screen.getByTestId("installation-license")).toHaveTextContent("Your personal plan is unchanged.");
  });

  it("says plainly when an installed license is not in effect", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: installation({}, { status: "EXPIRED", licensee: "Example Corp", expires_at: "2026-01-01T00:00:00Z" }) }));
    render(<InstallationCard />);

    expect(await screen.findByTestId("installation-license-problem")).toHaveTextContent("has expired, so it is not in effect");
    expect(screen.getByRole("heading", { name: "Community edition" })).toBeInTheDocument();
  });

  it("renders nothing when the status is unavailable or not an installation", async () => {
    fetchMock.mockResolvedValueOnce(apiErrorResponse(500, "INTERNAL_ERROR"));
    const { container, unmount } = render(<InstallationCard />);
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(container).toBeEmptyDOMElement();
    unmount();

    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { type: "billing_overview" } }));
    const second = render(<InstallationCard />);
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    expect(second.container).toBeEmptyDOMElement();
  });
});
