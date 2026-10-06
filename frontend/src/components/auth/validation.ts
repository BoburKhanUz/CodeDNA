import type { ValidationErrors } from "@/lib/api/types";

/** Client-side checks mirror the backend rules; the server remains authoritative. */

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export type FieldErrors<K extends string> = Partial<Record<K, string>>;

export function validateEmail(email: string): string | undefined {
  if (email.trim() === "") return "Enter your email address.";
  if (!EMAIL_PATTERN.test(email.trim())) return "Enter a valid email address.";
  return undefined;
}

export function validateNewPassword(password: string): string | undefined {
  if (password === "") return "Enter a password.";
  if (password.length < 8) return "Use at least 8 characters.";
  if (password.length > 72) return "Use at most 72 characters.";
  return undefined;
}

/** First server message per field, limited to the fields the form renders. */
export function firstServerErrors<K extends string>(fields: ValidationErrors, keys: readonly K[]): FieldErrors<K> {
  const result: FieldErrors<K> = {};
  for (const key of keys) {
    const message = fields[key]?.[0];
    if (message) result[key] = message;
  }
  return result;
}

export function hasErrors(errors: Record<string, string | undefined>): boolean {
  return Object.values(errors).some((message) => message !== undefined);
}
