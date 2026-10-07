import type { BillingOverview, BillingPlan, BillingQuota, BillingSubscription } from "@/lib/api/types";
import { BILLING_FEATURES } from "@/lib/api/types";

const LABELS: Record<string, string> = {
  PROJECTS: "Projects",
  SOURCE_ANALYSIS: "Source analysis",
  AI_ASSESSMENT: "AI assessment",
  CODING_CHALLENGES: "Coding challenges",
  LEARNING_ROADMAP: "Learning roadmap",
  GROWTH_ANALYTICS: "Growth analytics",
  HISTORICAL_DNA: "Historical DNA",
  GITHUB_INTEGRATION: "GitHub integration",
};

export function quota(key: string, overrides: Partial<BillingQuota> = {}): BillingQuota {
  return {
    key,
    label: key === "ACTIVE_PROJECTS" ? "Active projects" : key === "SOURCE_UPLOAD_BYTES" ? "Upload storage" : "Analyses",
    unit: key === "SOURCE_UPLOAD_BYTES" ? "BYTES" : "COUNT",
    period: key === "ACTIVE_PROJECTS" ? "CURRENT" : "MONTHLY",
    limit: 3,
    used: 1,
    remaining: 2,
    unlimited: false,
    resets_at: key === "ACTIVE_PROJECTS" ? null : "2026-11-01T00:00:00Z",
    ...overrides,
  };
}

export function freeOverview(overrides: Partial<BillingOverview> = {}): BillingOverview {
  return {
    type: "billing_overview",
    plan: { key: "FREE", version: "1.0.0", name: "Free" },
    source: "FREE_FALLBACK",
    status: "FREE",
    subscription: null,
    inactive_subscription: null,
    period: { start: "2026-10-01T00:00:00Z", end: "2026-11-01T00:00:00Z" },
    entitlements: BILLING_FEATURES.map((feature) => ({ feature, label: LABELS[feature] ?? feature, included: feature !== "AI_ASSESSMENT" })),
    quotas: [
      quota("ACTIVE_PROJECTS"),
      quota("ANALYSES", { limit: 60, used: 10, remaining: 50 }),
      quota("SOURCE_UPLOAD_BYTES", { limit: 524288000, used: 1048576, remaining: 523239424 }),
    ],
    ...overrides,
  };
}

export function subscription(overrides: Partial<BillingSubscription> = {}): BillingSubscription {
  return {
    id: "01hzzzzzzzzzzzzzzzzzzzzzzz",
    type: "billing_subscription",
    status: "ACTIVE",
    provider: "fake",
    plan: { key: "PRO", version: "1.0.0", name: "Pro" },
    grants_access: true,
    started_at: "2026-10-01T00:00:00Z",
    current_period_start: "2026-10-01T00:00:00Z",
    current_period_end: "2026-10-31T00:00:00Z",
    trial_ends_at: null,
    cancel_at_period_end: false,
    canceled_at: null,
    ended_at: null,
    ...overrides,
  };
}

export function proOverview(sub: Partial<BillingSubscription> = {}): BillingOverview {
  const current = subscription(sub);
  return freeOverview({
    plan: current.plan,
    source: "SUBSCRIPTION",
    status: current.status,
    subscription: current,
    period: { start: current.current_period_start, end: current.current_period_end },
    entitlements: BILLING_FEATURES.map((feature) => ({ feature, label: LABELS[feature] ?? feature, included: true })),
  });
}

function plan(key: string, name: string, monthly: number | null, annual: number | null, available = true): BillingPlan {
  return {
    type: "billing_plan",
    key,
    version: "1.0.0",
    name,
    description: `${name} plan`,
    status: available ? "ACTIVE" : "RESERVED",
    available,
    currency: "USD",
    prices: { monthly_minor: monthly, annual_minor: annual },
    features: BILLING_FEATURES.map((feature) => ({ key: feature, label: LABELS[feature] ?? feature, included: key !== "FREE" || feature !== "AI_ASSESSMENT" })),
    quotas: [],
  };
}

export const plans: BillingPlan[] = [plan("FREE", "Free", 0, 0), plan("PRO", "Pro", 1500, 15000), plan("TEAM_READY", "Team", null, null, false)];
