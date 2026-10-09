import { api } from "@/lib/api/client";
import type { BillingOverview, BillingPlan, DataEnvelope, Installation } from "@/lib/api/types";

/**
 * Billing (Phase 23): read-only. There is deliberately no function that
 * changes a plan: plans change only through the payment provider, never from
 * the browser.
 */
export async function getBillingOverview(): Promise<BillingOverview> {
  return (await api.get<DataEnvelope<BillingOverview>>("/api/v1/billing")).data;
}

export async function getBillingPlans(): Promise<BillingPlan[]> {
  return (await api.get<DataEnvelope<BillingPlan[]>>("/api/v1/billing/plans")).data;
}

/** The installation's edition and license status (Phase 27). Informational only. */
export async function getInstallation(): Promise<Installation> {
  return (await api.get<DataEnvelope<Installation>>("/api/v1/installation")).data;
}

/** A price in minor units as text, e.g. 1500 USD → "$15.00". Integer arithmetic only. */
export function formatPrice(minor: number | null, currency: string): string {
  if (minor === null) return "Not offered";
  if (minor === 0) return "Free";
  const major = Math.trunc(minor / 100);
  const cents = String(Math.abs(minor % 100)).padStart(2, "0");
  const symbol = currency === "USD" ? "$" : `${currency} `;
  return `${symbol}${major.toLocaleString("en-US")}.${cents}`;
}

/** A quota amount in its unit. */
export function formatAmount(value: number, unit: "COUNT" | "BYTES"): string {
  if (unit === "COUNT") return value.toLocaleString("en-US");
  const units = ["B", "KiB", "MiB", "GiB", "TiB"];
  let size = value;
  let index = 0;
  while (size >= 1024 && index < units.length - 1) {
    size /= 1024;
    index += 1;
  }
  return `${Number.isInteger(size) ? size : size.toFixed(1)} ${units[index]}`;
}
