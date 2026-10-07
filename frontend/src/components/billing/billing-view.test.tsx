import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BillingView } from "@/components/billing/billing-view";
import type { BillingOverview } from "@/lib/api/types";
import { freeOverview, plans, proOverview, quota, subscription } from "@/test/billing";
import { apiErrorResponse, jsonResponse } from "@/test/responses";
import { resetRouter, router } from "@/test/router";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  resetRouter();
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

function serve(overview: BillingOverview) {
  fetchMock.mockImplementation(async (input) =>
    String(input).endsWith("/billing/plans") ? jsonResponse({ data: plans }) : jsonResponse({ data: overview }),
  );
}

describe("BillingView", () => {
  it("shows the free plan, its usage, entitlements and the catalog", async () => {
    serve(freeOverview());
    render(<BillingView />);

    expect(await screen.findByRole("heading", { name: /Free plan/ })).toBeInTheDocument();
    expect(screen.getByTestId("billing-status")).toHaveTextContent("Free plan");
    expect(screen.getByTestId("billing-free")).toBeInTheDocument();
    const rows = screen.getAllByTestId("billing-quota");
    expect(within(rows[0]!).getByText("1 of 3")).toBeInTheDocument();
    expect(within(rows[2]!).getByText("1 MiB of 500 MiB")).toBeInTheDocument();
    const ai = within(screen.getByTestId("billing-entitlements")).getByText(/AI assessment/).closest("li");
    expect(ai).toHaveAttribute("data-included", "false");
    const cards = screen.getAllByTestId("billing-plan-card");
    expect(cards.map((c) => c.getAttribute("data-plan"))).toEqual(["FREE", "PRO", "TEAM_READY"]);
    expect(within(cards[1]!).getByText("$15.00")).toBeInTheDocument();
    expect(within(cards[1]!).getByText("$150.00 / year")).toBeInTheDocument();
    expect(cards[0]).toHaveAttribute("aria-current", "true");
    expect(within(cards[2]!).getByText("Not offered yet")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /upgrade|buy|subscribe/i })).not.toBeInTheDocument();
    expect(screen.queryByTestId("billing-exhausted")).not.toBeInTheDocument();
  });

  it("calls out an exhausted quota", async () => {
    serve(freeOverview({ quotas: [quota("ACTIVE_PROJECTS", { used: 3, remaining: 0 })] }));
    render(<BillingView />);

    expect(await screen.findByTestId("billing-exhausted")).toHaveTextContent("limit for active projects");
    expect(screen.getByTestId("billing-exhausted")).toHaveTextContent("Archive a project");
    expect(screen.getByTestId("billing-quota")).toHaveAttribute("data-exhausted", "true");
  });

  it("shows a subscription's renewal and cancellation state", async () => {
    serve(proOverview({ cancel_at_period_end: true }));
    render(<BillingView />);

    expect(await screen.findByRole("heading", { name: /Pro plan/ })).toBeInTheDocument();
    expect(screen.getByTestId("billing-renewal")).toHaveTextContent("you return to the free plan");
  });

  it("explains an overdue payment", async () => {
    serve(proOverview({ status: "PAST_DUE" }));
    render(<BillingView />);

    expect(await screen.findByTestId("billing-status")).toHaveTextContent("Payment overdue");
    expect(screen.getByTestId("billing-renewal")).toHaveTextContent("A payment failed");
  });

  it("says when a lapsed subscription no longer grants anything", async () => {
    serve(freeOverview({ inactive_subscription: subscription({ status: "PAUSED", grants_access: false }) }));
    render(<BillingView />);

    expect(await screen.findByTestId("billing-inactive")).toHaveTextContent("Pro subscription is paused");
  });

  it("sends a signed-out user to sign in", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(401, "AUTHENTICATION_REQUIRED"));
    render(<BillingView />);

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });

  it("shows an error and retries", async () => {
    fetchMock.mockImplementation(async () => apiErrorResponse(503, "BILLING_UNAVAILABLE"));
    render(<BillingView />);

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    serve(freeOverview());
    await userEvent.setup().click(screen.getByRole("button", { name: "Try again" }));
    expect(await screen.findByRole("heading", { name: /Free plan/ })).toBeInTheDocument();
  });
});
