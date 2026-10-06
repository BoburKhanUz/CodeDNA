import { describe, expect, it } from "vitest";

import {
  validateCountryCode,
  validateGithubUsername,
  validateHttpsUrl,
  validateMaxLength,
  validateTimezone,
} from "@/components/profile/validation";

describe("profile validation", () => {
  it("accepts empty or https URLs and rejects everything else", () => {
    expect(validateHttpsUrl("")).toBeUndefined();
    expect(validateHttpsUrl("https://ada.example.test/about")).toBeUndefined();
    for (const bad of ["http://example.test", "javascript:alert(1)", "data:text/html,x", "example.test", "/relative"]) {
      expect(validateHttpsUrl(bad), bad).toBe("Enter a full URL starting with https://.");
    }
    expect(validateHttpsUrl("https://user:secret@example.test")).toBe("The URL must not contain a username or password.");
  });

  it("checks GitHub usernames like the backend", () => {
    for (const ok of ["", "octocat", "octo-cat", "@octocat", "a".repeat(39)]) {
      expect(validateGithubUsername(ok), ok).toBeUndefined();
    }
    for (const bad of ["-ada", "ada-", "ada--l", "a".repeat(40), "ada l"]) {
      expect(validateGithubUsername(bad), bad).toBeDefined();
    }
  });

  it("checks country codes, lengths and the time zone", () => {
    expect(validateCountryCode("uz")).toBeUndefined();
    expect(validateCountryCode("UZB")).toBeDefined();
    expect(validateMaxLength("a".repeat(101), 100)).toBe("Use at most 100 characters.");
    expect(validateTimezone(" ")).toBe("Choose a time zone.");
    expect(validateTimezone("Asia/Tashkent")).toBeUndefined();
  });
});
