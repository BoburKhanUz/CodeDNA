/**
 * TypeScript mirror of the Laravel API contract (docs/api/README.md).
 *
 * Source of truth: backend/app/Http/Resources/{User,DeveloperProfile,Project,SourceSnapshot}Resource.php,
 * backend/app/Http/Resources/PaginatedCollection.php,
 * backend/app/Enums/{SupportedLocale,ProgrammingLanguage}.php and
 * backend/app/Http/Errors/{ErrorCode,ApiExceptionRenderer}.php. When those
 * change, update this file and the docs in the same commit.
 */

/** Successful responses wrap their payload in `data`. */
export interface DataEnvelope<T> {
  data: T;
}

/** Page information of a paginated collection (`?page=…&per_page=…`). */
export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

/** A page of a collection: `{"data": [...], "meta": {...}}`. */
export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
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
  "PROJECT_ARCHIVED",
  "INVALID_SOURCE_TYPE",
  "IDEMPOTENCY_KEY_REUSED",
  "SOURCE_ARCHIVE_INVALID",
  "SOURCE_ARCHIVE_UNSAFE",
  "SOURCE_ARCHIVE_TOO_LARGE",
  "SOURCE_UNCOMPRESSED_SIZE_EXCEEDED",
  "SOURCE_FILE_COUNT_EXCEEDED",
  "SOURCE_FILE_TOO_LARGE",
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

/** How a project's source arrives. Only UPLOAD accepts source today; REPOSITORY is descriptive. */
export const SOURCE_TYPES = ["UPLOAD", "REPOSITORY"] as const;
export type SourceType = (typeof SOURCE_TYPES)[number];

export const PROJECT_STATUSES = ["ACTIVE", "ARCHIVED"] as const;
export type ProjectStatus = (typeof PROJECT_STATUSES)[number];

/** `ProjectResource` — /api/v1/projects. */
export interface Project {
  /** ULID (26 characters, lowercase). */
  id: string;
  type: "project";
  name: string;
  slug: string;
  description: string | null;
  default_branch: string | null;
  source_type: SourceType;
  /** https:// URL; only for REPOSITORY projects. */
  repository_url: string | null;
  /** A ProgrammingLanguage value, or null. */
  language: string | null;
  status: ProjectStatus;
  created_at: string | null;
  updated_at: string | null;
}

export type ProjectResponse = DataEnvelope<Project>;

/** POST /api/v1/projects. */
export interface CreateProjectRequest {
  name: string;
  slug: string;
  source_type: SourceType;
  description?: string | null;
  default_branch?: string | null;
  repository_url?: string | null;
  language?: string | null;
}

/** `SourceSnapshotResource` — immutable; never includes storage details or source. */
export interface SourceSnapshot {
  id: string;
  type: "source_snapshot";
  project_id: string;
  /** 1, 2, 3, … per project. */
  version: number;
  source_type: SourceType;
  /** Server-computed SHA-256 of the uploaded archive (lowercase hex). */
  source_hash: string;
  size_bytes: number;
  /** Regular files in the archive (directories and __MACOSX/ excluded). */
  file_count: number;
  /** File-extension heuristic, not analysis; null when unknown. */
  primary_language: string | null;
  created_at: string | null;
}

export type SourceSnapshotResponse = DataEnvelope<SourceSnapshot>;

/**
 * Default server limit for uploaded archives (SOURCE_MAX_ARCHIVE_BYTES, 50 MiB).
 * Used only for an early client-side check; the server is authoritative.
 */
export const MAX_ARCHIVE_BYTES = 50 * 1024 * 1024;
