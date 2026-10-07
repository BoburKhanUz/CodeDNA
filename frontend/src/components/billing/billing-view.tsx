"use client";

import { Check, Minus } from "lucide-react";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { BillingOverview, BillingPlan, BillingQuota, BillingSubscription } from "@/lib/api/types";
import { formatAmount, formatPrice, getBillingOverview, getBillingPlans } from "@/lib/billing/client";
import { formatDateTime } from "@/lib/projects/format";

type State =
  | { status: "loading" }
  | { status: "error"; error: unknown }
  | { status: "ready"; overview: BillingOverview; plans: BillingPlan[] };

const STATUS_LABELS: Record<BillingOverview["status"], string> = {
  FREE: "Free plan",
  TRIALING: "Trial",
  ACTIVE: "Active",
  PAST_DUE: "Payment overdue",
  PAUSED: "Paused",
  CANCELED: "Canceled",
  EXPIRED: "Expired",
};

/**
 * /app/billing (Phase 23): the plan the server applies, what it includes,
 * what has been used and the plan catalog. Display only: access is decided
 * by the server, and nothing on this page changes a plan.
 */
export function BillingView() {
  const router = useRouter();
  const [state, setState] = useState<State>({ status: "loading" });

  const load = useCallback(() => {
    Promise.all([getBillingOverview(), getBillingPlans()])
      .then(([overview, plans]) => setState({ status: "ready", overview, plans }))
      .catch((error: unknown) => {
        if (isApiError(error) && error.status === 401) {
          router.replace("/login");
          router.refresh();
          return;
        }
        setState({ status: "error", error });
      });
  }, [router]);

  useEffect(() => load(), [load]);

  if (state.status === "loading") {
    return (
      <div className="grid max-w-4xl gap-6" role="status" aria-label="Loading billing">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-32" />
        <Skeleton className="h-64" />
        <span className="sr-only">Loading billing…</span>
      </div>
    );
  }

  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">Billing</h1>
        <ApiErrorAlert error={state.error} />
        <Button variant="outline" className="w-fit" onClick={() => (setState({ status: "loading" }), load())}>
          Try again
        </Button>
      </div>
    );
  }

  const { overview, plans } = state;
  const exhausted = overview.quotas.filter((q) => q.limit !== null && q.remaining === 0);

  return (
    <div className="grid max-w-4xl gap-6">
      <div className="grid gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">Billing</h1>
        <p className="text-muted-foreground">Your plan, what it includes and what you have used this period.</p>
      </div>

      <Card data-testid="billing-plan">
        <CardHeader>
          <CardTitle>
            <h2>
              {overview.plan.name} <span className="text-muted-foreground text-sm font-normal">plan</span>
            </h2>
          </CardTitle>
          <CardDescription>
            <span className="bg-muted inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" data-testid="billing-status">
              {STATUS_LABELS[overview.status]}
            </span>
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-3 text-sm">
          {overview.subscription === null ? (
            <p data-testid="billing-free">You are on the free plan. It never expires and costs nothing.</p>
          ) : (
            <SubscriptionSummary subscription={overview.subscription} />
          )}
          {overview.inactive_subscription !== null ? <InactiveNotice subscription={overview.inactive_subscription} /> : null}
          <p className="text-muted-foreground" data-testid="billing-period">
            Usage period: {formatDateTime(overview.period.start)} to {formatDateTime(overview.period.end)}
          </p>
        </CardContent>
      </Card>

      {exhausted.length > 0 ? (
        <div role="note" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-50" data-testid="billing-exhausted">
          You have reached your plan&apos;s limit for {exhausted.map((q) => q.label.toLowerCase()).join(", ")}.{" "}
          {exhausted.some((q) => q.resets_at !== null) ? "Monthly limits start again when the period resets." : "Archive a project to free a slot."}
        </div>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Usage</h2>
          </CardTitle>
          <CardDescription>Counted by the server. Requests over a limit are refused and use nothing.</CardDescription>
        </CardHeader>
        <CardContent>
          <table className="w-full text-sm" data-testid="billing-quotas">
            <thead className="text-muted-foreground text-left">
              <tr>
                <th className="py-1 font-medium">Limit</th>
                <th className="py-1 font-medium">Used</th>
                <th className="py-1 font-medium">Remaining</th>
                <th className="py-1 font-medium">Resets</th>
              </tr>
            </thead>
            <tbody>
              {overview.quotas.map((quota) => (
                <QuotaRow key={quota.key} quota={quota} />
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Included features</h2>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="grid gap-1 text-sm sm:grid-cols-2" data-testid="billing-entitlements">
            {overview.entitlements.map((entitlement) => (
              <li key={entitlement.feature} className="flex items-center gap-2" data-included={entitlement.included}>
                {entitlement.included ? <Check className="size-4" aria-hidden="true" /> : <Minus className="text-muted-foreground size-4" aria-hidden="true" />}
                <span className={entitlement.included ? undefined : "text-muted-foreground"}>
                  {entitlement.label}
                  <span className="sr-only">{entitlement.included ? " (included)" : " (not included)"}</span>
                </span>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <section aria-labelledby="plans-heading" className="grid gap-3">
        <h2 id="plans-heading" className="text-lg font-semibold">
          Plans
        </h2>
        <p className="text-muted-foreground text-sm">Online upgrades are not available yet. No payment details are collected here.</p>
        <div className="grid gap-4 md:grid-cols-3" data-testid="billing-plans">
          {plans.map((plan) => (
            <PlanCard key={`${plan.key}@${plan.version}`} plan={plan} current={plan.key === overview.plan.key} />
          ))}
        </div>
      </section>
    </div>
  );
}

function SubscriptionSummary({ subscription }: { subscription: BillingSubscription }) {
  return (
    <dl className="grid gap-2 sm:grid-cols-[12rem_1fr]" data-testid="billing-subscription">
      <dt className="text-muted-foreground">Current period ends</dt>
      <dd>{formatDateTime(subscription.current_period_end)}</dd>
      {subscription.trial_ends_at !== null ? (
        <>
          <dt className="text-muted-foreground">Trial ends</dt>
          <dd>{formatDateTime(subscription.trial_ends_at)}</dd>
        </>
      ) : null}
      <dt className="text-muted-foreground">Renewal</dt>
      <dd data-testid="billing-renewal">
        {subscription.cancel_at_period_end
          ? `Canceled: the plan ends on ${formatDateTime(subscription.current_period_end)} and you return to the free plan.`
          : subscription.status === "PAST_DUE"
            ? "A payment failed. The plan stays active until the end of the paid period."
            : "Renews automatically at the end of the period."}
      </dd>
    </dl>
  );
}

function InactiveNotice({ subscription }: { subscription: BillingSubscription }) {
  return (
    <p role="note" className="rounded-md border p-3" data-testid="billing-inactive">
      Your {subscription.plan.name} subscription is {STATUS_LABELS[subscription.status].toLowerCase()} and grants nothing right now, so the free plan
      applies.
    </p>
  );
}

function QuotaRow({ quota }: { quota: BillingQuota }) {
  const full = quota.limit !== null && quota.remaining === 0;
  return (
    <tr className="border-t" data-testid="billing-quota" data-quota={quota.key} data-exhausted={full}>
      <th scope="row" className="py-2 pr-2 text-left font-normal">
        {quota.label}
      </th>
      <td className="py-2 pr-2">
        {formatAmount(quota.used, quota.unit)}
        {quota.limit === null ? null : ` of ${formatAmount(quota.limit, quota.unit)}`}
      </td>
      <td className={full ? "py-2 pr-2 font-medium text-red-700 dark:text-red-300" : "py-2 pr-2"}>
        {quota.limit === null ? "Unlimited" : quota.limit === 0 ? "Not included" : formatAmount(quota.remaining ?? 0, quota.unit)}
      </td>
      <td className="text-muted-foreground py-2">{quota.resets_at === null ? "—" : formatDateTime(quota.resets_at)}</td>
    </tr>
  );
}

function PlanCard({ plan, current }: { plan: BillingPlan; current: boolean }) {
  return (
    <Card data-testid="billing-plan-card" data-plan={plan.key} aria-current={current ? "true" : undefined}>
      <CardHeader>
        <CardTitle>
          <h3>{plan.name}</h3>
        </CardTitle>
        <CardDescription>{plan.description}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-2 text-sm">
        <p className="text-lg font-semibold">
          {formatPrice(plan.prices.monthly_minor, plan.currency)}
          {plan.prices.monthly_minor ? <span className="text-muted-foreground text-sm font-normal"> / month</span> : null}
        </p>
        {plan.prices.annual_minor ? (
          <p className="text-muted-foreground">{formatPrice(plan.prices.annual_minor, plan.currency)} / year</p>
        ) : null}
        {current ? <p className="font-medium">Your current plan</p> : null}
        {!plan.available ? <p className="text-muted-foreground">Not offered yet</p> : null}
        <ul className="grid gap-1">
          {plan.features
            .filter((f) => f.included)
            .map((feature) => (
              <li key={feature.key}>{feature.label}</li>
            ))}
        </ul>
      </CardContent>
    </Card>
  );
}
