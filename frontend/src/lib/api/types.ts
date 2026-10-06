/**
 * TypeScript mirror of the Laravel API contract (docs/api/README.md).
 *
 * Source of truth: backend/app/Http/Resources/{User,DeveloperProfile,Project,SourceSnapshot,AnalysisRun,DnaSnapshot,DnaSnapshotSummary,CompetencySnapshot,CompetencySnapshotSummary,SkillGapSnapshot,SkillGapSnapshotSummary}Resource.php,
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

/** Analysis result types (Phase 10). Only `static_analysis` results are scored. */
export type AnalysisResultType = "foundation" | "static_analysis";

export const ANALYSIS_RUN_STATUSES = ["QUEUED", "RUNNING", "SUCCEEDED", "FAILED", "CANCELLED"] as const;
export type AnalysisRunStatus = (typeof ANALYSIS_RUN_STATUSES)[number];

/** `AnalysisRunResource` — GET /api/v1/projects/{project}/analyses (read here; started through the API). */
export interface AnalysisRun {
  id: string;
  type: "analysis_run";
  project_id: string;
  source_snapshot_id: string;
  result_type: AnalysisResultType;
  status: AnalysisRunStatus;
  created_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  failure: { code: string; message: string } | null;
  result: {
    result_hash: string;
    versions: { contract: string | null; analyzer: string | null; ir: string | null; metrics: string | null };
  } | null;
}

/*
 * CodeDNA (Phase 11 scoring, Phase 12 read API; docs/architecture/dna-scoring-v1.md).
 * Every score, weight, contribution and data-quality value is the backend's
 * decimal string with exactly 4 places on a 0–1 scale ("0.8050"); the
 * frontend formats these strings and never computes them.
 */

/** A 0–1 decimal with 4 places, as computed by the scoring engine, e.g. "0.8050". */
export type DecimalString = string;

/** READY: an overall score exists. INSUFFICIENT_DATA: too little evidence for one (not a score of 0). */
export type DnaSnapshotStatus = "READY" | "INSUFFICIENT_DATA";

/** SCORED: part of the overall score. UNAVAILABLE: no score; its weight was redistributed. */
export type DnaDimensionStatus = "SCORED" | "UNAVAILABLE";

/** Availability of one component's evidence; never conflated with each other or with 0. */
export const DNA_EVIDENCE_STATUSES = ["AVAILABLE", "INSUFFICIENT_EVIDENCE", "UNSUPPORTED", "MISSING"] as const;
export type DnaEvidenceStatus = (typeof DNA_EVIDENCE_STATUSES)[number];

/** `DnaSnapshotSummaryResource` — GET /api/v1/projects/{project}/dna (newest first). */
export interface DnaSnapshotSummary {
  id: string;
  type: "dna_snapshot";
  project_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
  source_snapshot_version: number | null;
  status: DnaSnapshotStatus;
  /** null unless READY. */
  overall_score: DecimalString | null;
  /** Objective input coverage; not a confidence or probability. null only on pre-1.0.0 records. */
  data_quality: DecimalString | null;
  scoring_version: string;
  metrics_version: string | null;
  created_at: string | null;
}

/** One measured input of a component: a metric path of the analysis result and its count. */
export interface DnaMetricEvidence {
  metric: string;
  /** Raw integer count; null when the analysis did not provide it. */
  value: number | null;
}

export interface DnaComponent {
  key: string;
  description: string | null;
  /** true: value is a share of the denominator (e.g. 3 of 42 functions); null for unknown scoring versions. */
  share: boolean | null;
  status: DnaEvidenceStatus;
  required: boolean | null;
  weight: DecimalString | null;
  /** numerator / denominator, 4 places; null unless AVAILABLE. */
  value: DecimalString | null;
  /** null unless AVAILABLE. */
  score: DecimalString | null;
  /** Thresholds: score 1 at or below `best`, 0 at or above `worst`. */
  best: DecimalString | null;
  worst: DecimalString | null;
  minimum_denominator: number | null;
  numerator: DnaMetricEvidence[];
  denominator: DnaMetricEvidence[];
}

export interface DnaDimension {
  /** Stable identifier, e.g. "COMPLEXITY". */
  dimension: string;
  name: string;
  description: string | null;
  status: DnaDimensionStatus;
  /** Evidence status of the first failing required component; null when SCORED. */
  unavailable_reason: Exclude<DnaEvidenceStatus, "AVAILABLE"> | null;
  score: DecimalString | null;
  /** Weight in the scoring specification. */
  weight: DecimalString | null;
  /** Weight after renormalization over scored dimensions; null unless part of the overall score. */
  effective_weight: DecimalString | null;
  /** score × effective_weight, as computed by the engine; null unless part of the overall score. */
  contribution: DecimalString | null;
  data_quality: DecimalString | null;
  components: DnaComponent[];
}

export interface DnaAggregation {
  method: string | null;
  minimum_scored_dimensions: number | null;
  scored_dimensions: string[];
  unavailable_dimensions: string[];
  scored_weight: DecimalString | null;
  renormalized: boolean | null;
}

export interface DnaDataQualityBreakdown {
  parse_coverage: { value: DecimalString | null; weight: DecimalString | null; files_parsed: number | null; files_analyzable: number | null };
  evidence_volume: { value: DecimalString | null; weight: DecimalString | null; functions: number | null; target: number | null };
  metric_availability: {
    value: DecimalString | null;
    weight: DecimalString | null;
    available_components: number | null;
    components: number | null;
  };
}

/** `DnaSnapshotResource` — GET /api/v1/projects/{project}/dna/{snapshot}. Immutable. */
export interface DnaSnapshot {
  id: string;
  type: "dna_snapshot";
  project_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
  status: DnaSnapshotStatus;
  overall_score: DecimalString | null;
  data_quality: DecimalString | null;
  scoring_version: string;
  specification_fingerprint: string | null;
  versions: { scoring: string; metrics: string | null; analyzer: string | null; ir: string | null; contract: string | null };
  result_hash: string;
  created_at: string | null;
  source_snapshot: { id: string; version: number; file_count: number; primary_language: string | null; created_at: string | null } | null;
  analysis_run: { id: string; result_type: AnalysisResultType; status: AnalysisRunStatus; completed_at: string | null } | null;
  /** In the scoring specification's order. */
  dimensions: DnaDimension[];
  aggregation: DnaAggregation | null;
  /** Number of components per evidence status. */
  availability: Record<DnaEvidenceStatus, number> | null;
  data_quality_breakdown: DnaDataQualityBreakdown | null;
}

export type DnaSnapshotResponse = DataEnvelope<DnaSnapshot>;

/*
 * Competency matrix (Phase 13; docs/architecture/competency-matrix-v1.md).
 * Levels describe how strongly the analyzed source code meets measurable
 * criteria. They are not seniority, career or employment levels. Scores and
 * evidence quality are backend decimal strings; the frontend never computes them.
 */

export const COMPETENCY_LEVELS = ["NOT_ESTABLISHED", "DEVELOPING", "ESTABLISHED", "STRONG"] as const;
export type CompetencyLevel = (typeof COMPETENCY_LEVELS)[number];

/** ASSESSED, or why the competency could not be assessed (then no score and no level, never 0). */
export const COMPETENCY_STATUSES = ["ASSESSED", "INSUFFICIENT_EVIDENCE", "UNSUPPORTED", "MISSING"] as const;
export type CompetencyStatus = (typeof COMPETENCY_STATUSES)[number];

/** ASSESSED: at least one competency was assessed. INSUFFICIENT_DATA: none could be. */
export type CompetencySnapshotStatus = "ASSESSED" | "INSUFFICIENT_DATA";

/** Stored counts per status and level; there is deliberately no aggregate score. */
export interface CompetencySummary {
  competencies: number;
  statuses: Record<CompetencyStatus, number>;
  levels: Record<CompetencyLevel, number>;
}

/** `CompetencySnapshotSummaryResource` — GET /api/v1/projects/{project}/competencies (newest first). */
export interface CompetencySnapshotSummary {
  id: string;
  type: "competency_snapshot";
  project_id: string;
  dna_snapshot_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
  status: CompetencySnapshotStatus;
  competency_version: string;
  dna_scoring_version: string;
  summary: CompetencySummary;
  created_at: string | null;
}

/** One DNA component used as evidence, passed through from the DNA snapshot. */
export interface CompetencyEvidence {
  /** "DIMENSION.component", e.g. "COMPLEXITY.mean_cyclomatic_complexity". */
  source: string;
  dimension: string | null;
  component: string | null;
  /** Why this evidence belongs to the competency; null for unknown competency versions. */
  rationale: string | null;
  /** true: value is a share of the denominator (from the DNA scoring specification); null when unknown. */
  share: boolean | null;
  weight: DecimalString | null;
  required: boolean | null;
  status: DnaEvidenceStatus;
  value: DecimalString | null;
  score: DecimalString | null;
  best: DecimalString | null;
  worst: DecimalString | null;
  minimum_denominator: number | null;
  numerator: DnaMetricEvidence[];
  denominator: DnaMetricEvidence[];
}

export interface Competency {
  key: string;
  name: string;
  description: string | null;
  status: CompetencyStatus;
  /** null unless ASSESSED. */
  score: DecimalString | null;
  /** null unless ASSESSED. */
  level: CompetencyLevel | null;
  /** Objective input coverage of this competency's evidence; not a confidence or probability. */
  evidence_quality: DecimalString | null;
  evidence_quality_terms: { parse_coverage: DecimalString; evidence_volume: DecimalString; evidence_availability: DecimalString } | null;
  /** Measured languages for which the evidence is only partially supported. */
  limitations: { language: string; note: string }[];
  evidence: CompetencyEvidence[];
}

export interface CompetencyLevelBound {
  level: CompetencyLevel;
  name: string;
  ordinal: number;
  minimum_score: DecimalString;
}

/** `CompetencySnapshotResource` — GET /api/v1/projects/{project}/competencies/{snapshot}. Immutable. */
export interface CompetencySnapshot {
  id: string;
  type: "competency_snapshot";
  project_id: string;
  dna_snapshot_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
  status: CompetencySnapshotStatus;
  competency_version: string;
  specification_fingerprint: string;
  dna_scoring_version: string;
  created_at: string | null;
  /** Level boundaries of this competency version (null for unknown versions). */
  levels: CompetencyLevelBound[] | null;
  summary: CompetencySummary;
  /** Languages the analysis measured; null when unknown. */
  languages: string[] | null;
  dna_snapshot: {
    id: string;
    status: DnaSnapshotStatus;
    overall_score: DecimalString | null;
    data_quality: DecimalString | null;
    scoring_version: string;
    specification_fingerprint: string | null;
    created_at: string | null;
  } | null;
  source_snapshot: { id: string; version: number; file_count: number; primary_language: string | null; created_at: string | null } | null;
  analysis_run: { id: string; result_type: AnalysisResultType; status: AnalysisRunStatus; completed_at: string | null } | null;
  /** In the competency specification's order. */
  competencies: Competency[];
}

export type CompetencySnapshotResponse = DataEnvelope<CompetencySnapshot>;

/*
 * Skill gap analysis (Phase 14; docs/architecture/skill-gap-v1.md). A gap is
 * the difference between a measured competency score and a versioned,
 * server-owned target. It describes the analyzed code, never a person.
 * Every value is a backend decimal string; the frontend never computes one.
 */

/** GAP / NO_GAP: measured. The others: no gap could be measured (never "target − 0"). */
export const SKILL_GAP_STATUSES = ["GAP", "NO_GAP", "INSUFFICIENT_EVIDENCE", "UNSUPPORTED", "MISSING", "NOT_TARGETED"] as const;
export type SkillGapStatus = (typeof SKILL_GAP_STATUSES)[number];

export const GAP_PRIORITIES = ["LOW", "MEDIUM", "HIGH"] as const;
export type GapPriority = (typeof GAP_PRIORITIES)[number];

/** GAPS_IDENTIFIED, NO_MATERIAL_GAPS (measured, none material) or INSUFFICIENT_DATA (nothing measured). */
export type SkillGapSnapshotStatus = "GAPS_IDENTIFIED" | "NO_MATERIAL_GAPS" | "INSUFFICIENT_DATA";

/** Stored counts; there is deliberately no aggregate gap. */
export interface SkillGapSummary {
  competencies: number;
  material_gaps: number;
  statuses: Record<SkillGapStatus, number>;
  priorities: Record<GapPriority, number>;
}

export interface TargetProfileRef {
  key: string;
  version: string;
}

/** `SkillGapSnapshotSummaryResource` — GET /api/v1/projects/{project}/skill-gaps (newest first). */
export interface SkillGapSnapshotSummary {
  id: string;
  type: "skill_gap_snapshot";
  project_id: string;
  competency_snapshot_id: string;
  dna_snapshot_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
  status: SkillGapSnapshotStatus;
  skill_gap_version: string;
  target_profile: TargetProfileRef;
  competency_version: string;
  summary: SkillGapSummary;
  created_at: string | null;
}

export interface SkillGapResult {
  competency_key: string;
  name: string;
  status: SkillGapStatus;
  /** Null unless measured (GAP, NO_GAP) or for an assessed untargeted competency. */
  current_score: DecimalString | null;
  /** Null only when NOT_TARGETED. */
  target_score: DecimalString | null;
  /** max(target − current, 0); null unless measured. Kept even when not material. */
  raw_gap: DecimalString | null;
  material_gap: boolean | null;
  /** Only for GAP. */
  priority: GapPriority | null;
  /** true: a HIGH gap was capped at MEDIUM because evidence quality is below the bound. */
  priority_capped: boolean | null;
  evidence_quality: DecimalString | null;
  competency_status: CompetencyStatus | null;
  current_level: CompetencyLevel | null;
  target_rationale: string | null;
  limitations: { language: string | null; note: string | null }[];
  evidence: { source: string | null; status: string | null; value: DecimalString | null; score: DecimalString | null }[];
}

/** `SkillGapSnapshotResource` — GET /api/v1/projects/{project}/skill-gaps/{snapshot}. Immutable. */
export interface SkillGapSnapshot {
  id: string;
  type: "skill_gap_snapshot";
  project_id: string;
  competency_snapshot_id: string;
  dna_snapshot_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
  status: SkillGapSnapshotStatus;
  skill_gap_version: string;
  specification_fingerprint: string;
  target_profile: TargetProfileRef & { description: string | null };
  thresholds: {
    material_gap: DecimalString;
    priorities: { priority: GapPriority; minimum_gap: DecimalString }[];
    high_priority_minimum_evidence_quality: DecimalString;
  } | null;
  competency_version: string;
  dna_scoring_version: string;
  created_at: string | null;
  summary: SkillGapSummary;
  languages: string[] | null;
  competency_snapshot: { id: string; status: CompetencySnapshotStatus; specification_fingerprint: string | null; created_at: string | null } | null;
  source_snapshot: { id: string; version: number; file_count: number; primary_language: string | null; created_at: string | null } | null;
  /** Targeted competencies in profile order, then untargeted ones. */
  results: SkillGapResult[];
}

export type SkillGapSnapshotResponse = DataEnvelope<SkillGapSnapshot>;
