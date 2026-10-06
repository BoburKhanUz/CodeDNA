/**
 * Client-side checks mirroring backend/app/Http/Requests/Profile/UpdateDeveloperProfileRequest.php.
 * They give instant feedback; the server remains authoritative.
 */

export const MAX_LENGTH = {
  display_name: 100,
  bio: 1000,
  city: 100,
  job_title: 100,
  company: 100,
  url: 2048,
  github_username: 39,
} as const;

const GITHUB_USERNAME = /^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/;
const COUNTRY_CODE = /^[A-Za-z]{2}$/;

export function validateMaxLength(value: string, max: number): string | undefined {
  return value.length > max ? `Use at most ${max} characters.` : undefined;
}

/** Empty is allowed (clears the field); otherwise an absolute https:// URL without credentials. */
export function validateHttpsUrl(value: string): string | undefined {
  const trimmed = value.trim();
  if (trimmed === "") return undefined;
  if (trimmed.length > MAX_LENGTH.url) return `Use at most ${MAX_LENGTH.url} characters.`;
  let url: URL;
  try {
    url = new URL(trimmed);
  } catch {
    return "Enter a full URL starting with https://.";
  }
  if (url.protocol !== "https:" || url.hostname === "" || /\s/.test(trimmed)) {
    return "Enter a full URL starting with https://.";
  }
  if (url.username !== "" || url.password !== "") {
    return "The URL must not contain a username or password.";
  }
  return undefined;
}

export function validateGithubUsername(value: string): string | undefined {
  const username = value.trim().replace(/^@/, "");
  if (username === "") return undefined;
  if (!GITHUB_USERNAME.test(username)) {
    return "Use letters, digits and single hyphens (at most 39 characters, no hyphen at the start or end).";
  }
  return undefined;
}

export function validateCountryCode(value: string): string | undefined {
  const code = value.trim();
  if (code === "") return undefined;
  return COUNTRY_CODE.test(code) ? undefined : "Use a two-letter country code, such as UZ.";
}

export function validateTimezone(value: string): string | undefined {
  return value.trim() === "" ? "Choose a time zone." : undefined;
}
