/**
 * TypeScript mirror of the Laravel API contract (docs/api/README.md).
 *
 * Source of truth: backend/app/Http/Resources/UserResource.php and
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
