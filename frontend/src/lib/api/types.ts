/**
 * TypeScript mirror of the Laravel API contract (docs/api/README.md).
 *
 * Source of truth: backend/app/Http/Resources/{UserResource,DeveloperProfileResource}.php,
 * backend/app/Enums/{SupportedLocale,ProgrammingLanguage}.php and
 * backend/app/Http/Errors/{ErrorCode,ApiExceptionRenderer}.php. When those
 * change, update this file and the docs in the same commit.
 */

/** Successful responses wrap their payload in `data`. */
export interface DataEnvelope<T> {
  data: T;
}

/** The public API's error vocabulary (backend ErrorCode enum). */
export const API_ERROR_CODES = [
  "BAD_REQUEST",
  "AUTHENTICATION_REQUIRED",
  "INVALID_CREDENTIALS",
  "FORBIDDEN",
  "RESOURCE_NOT_FOUND",
  "METHOD_NOT_ALLOWED",
  "PAYLOAD_TOO_LARGE",
  "CSRF_TOKEN_MISMATCH",
  "VALIDATION_FAILED",
  "RATE_LIMITED",
  "INTERNAL_ERROR",
  "SERVICE_UNAVAILABLE",
] as const;

export type ApiErrorCode = (typeof API_ERROR_CODES)[number];

/** Field name -> validation messages (`error.details.fields`). */
export type ValidationErrors = Record<string, string[]>;

/** Every API error response: `{"error": {...}}`. */
export interface ApiErrorEnvelope {
  error: {
    code: ApiErrorCode;
    message: string;
    request_id: string | null;
    /** Present only when there are details; today only for VALIDATION_FAILED. */
    details?: {
      fields?: ValidationErrors;
    };
  };
}

/** `UserResource` — returned by register, login and GET /api/v1/me. */
export interface User {
  /** ULID (26 characters, lowercase). */
  id: string;
  type: "user";
  name: string;
  email: string;
  /** ISO 8601 UTC (`2026-10-05T12:00:00Z`) or null. */
  email_verified_at: string | null;
  created_at: string | null;
}

/** POST /api/v1/auth/login and /register respond with the user. */
export type AuthResponse = DataEnvelope<User>;

export interface LoginRequest {
  email: string;
  password: string;
}

export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

/** Interface languages (backend SupportedLocale enum). A stored preference; the UI is not translated yet. */
export const SUPPORTED_LOCALES = ["en", "uz", "ru"] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

/** Preferred programming languages (backend ProgrammingLanguage enum). */
export const PROGRAMMING_LANGUAGES = [
  "c",
  "cpp",
  "csharp",
  "dart",
  "elixir",
  "go",
  "java",
  "javascript",
  "kotlin",
  "php",
  "python",
  "ruby",
  "rust",
  "scala",
  "swift",
  "typescript",
] as const;
export type ProgrammingLanguage = (typeof PROGRAMMING_LANGUAGES)[number];

/** `DeveloperProfileResource` — GET and PATCH /api/v1/profile. */
export interface DeveloperProfile {
  /** ULID (26 characters, lowercase). */
  id: string;
  type: "developer_profile";
  display_name: string | null;
  bio: string | null;
  /** https:// URL or null. */
  avatar_url: string | null;
  /** IANA time zone, e.g. "Asia/Tashkent". Presentation preference; API timestamps stay UTC. */
  timezone: string;
  locale: SupportedLocale;
  /** Two uppercase letters, e.g. "UZ". */
  country_code: string | null;
  city: string | null;
  job_title: string | null;
  company: string | null;
  website_url: string | null;
  github_username: string | null;
  linkedin_url: string | null;
  /** A ProgrammingLanguage value, kept as string for forward compatibility. */
  preferred_language: string | null;
  updated_at: string | null;
}

export type DeveloperProfileResponse = DataEnvelope<DeveloperProfile>;

/** PATCH /api/v1/profile: send only the fields to change; null clears an optional field. */
export type UpdateProfileRequest = Partial<Omit<DeveloperProfile, "id" | "type" | "updated_at">>;

/** PATCH /api/v1/auth/password (204; the session ends). */
export interface ChangePasswordRequest {
  current_password: string;
  password: string;
  password_confirmation: string;
}
