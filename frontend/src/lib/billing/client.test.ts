import { describe, expect, it } from "vitest";

import { ApiError, describeApiError } from "@/lib/api/errors";
import { formatAmount, formatPrice } from "@/lib/billing/client";

describe("billing formatting", () => {
  it("formats integer minor units without floating point", () => {
    expect(formatPrice(1500, "USD")).toBe("$15.00");
    expect(formatPrice(15000, "USD")).toBe("$150.00");
    expect(formatPrice(123456789, "USD")).toBe("$1,234,567.89");
    expect(formatPrice(7, "EUR")).toBe("EUR 0.07");
    expect(formatPrice(0, "USD")).toBe("Free");
    expect(formatPrice(null, "USD")).toBe("Not offered");
  });

  it("formats quota amounts in their unit", () => {
    expect(formatAmount(1000, "COUNT")).toBe("1,000");
    expect(formatAmount(524288000, "BYTES")).toBe("500 MiB");
    expect(formatAmount(10737418240, "BYTES")).toBe("10 GiB");
    expect(formatAmount(1536, "BYTES")).toBe("1.5 KiB");
    expect(formatAmount(0, "BYTES")).toBe("0 B");
  });
});

describe("billing error messages", () => {
  it("explains each billing refusal without server text", () => {
    const text = (code: "FEATURE_NOT_INCLUDED" | "SUBSCRIPTION_INACTIVE" | "QUOTA_EXCEEDED" | "BILLING_UNAVAILABLE", status: number) =>
      describeApiError(new ApiError({ status, code }));
    expect(text("FEATURE_NOT_INCLUDED", 402)).toMatch(/plan does not include/);
    expect(text("SUBSCRIPTION_INACTIVE", 402)).toMatch(/not active/);
    expect(text("QUOTA_EXCEEDED", 402)).toMatch(/reached your plan's limit/);
    expect(text("BILLING_UNAVAILABLE", 503)).toMatch(/not available/);
  });
});
