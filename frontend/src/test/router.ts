import { vi } from "vitest";

/** Shared mock for next/navigation's App Router hooks. */
export const router = {
  replace: vi.fn(),
  push: vi.fn(),
  refresh: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  prefetch: vi.fn(),
};

export function resetRouter() {
  for (const fn of Object.values(router)) fn.mockReset();
}
