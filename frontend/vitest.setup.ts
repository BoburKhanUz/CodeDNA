import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterEach, vi } from "vitest";

// `server-only` throws outside React Server Components; tests import server modules directly.
vi.mock("server-only", () => ({}));

afterEach(() => {
  cleanup();
  // Remove cookies set by a test (jsdom keeps document.cookie between tests).
  for (const cookie of document.cookie.split(";")) {
    const name = cookie.split("=")[0]?.trim();
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
  }
});
