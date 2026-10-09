/**
 * TypeScript mirror of the Laravel API contract (docs/api/README.md).
 *
 * Source of truth: backend/app/Http/Resources/{User,DeveloperProfile,Project,SourceSnapshot,AnalysisRun,DnaSnapshot,DnaSnapshotSummary,CompetencySnapshot,CompetencySnapshotSummary,SkillGapSnapshot,SkillGapSnapshotSummary,AiAssessment,AiAssessmentSummary,Challenge,ChallengeSummary,ChallengeSubmission,ChallengeSubmissionSummary,Roadmap,RoadmapSummary,GrowthSnapshot,GrowthSnapshotSummary,GitHubConnection,GitHubImport}Resource.php,
 * backend/app/Http/Controllers/Api/V1/{GitHub/GitHubAccountController,Projects/ProjectGitHubController}.php,
 * backend/app/Http/Controllers/Api/V1/Projects/GrowthController.php (growth overview),
 * backend/app/Http/Controllers/Api/V1/Projects/HistoryController.php and HistoryPointResource.php (historical DNA),
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

/** Cursor information of a keyset page (`?cursor=…&per_page=…`); a null cursor means nothing further that way. */
export interface CursorMeta {
  per_page: number;
  next_cursor: string | null;
  prev_cursor: string | null;
}

/** A keyset page of a long, append-only list: no total, opaque cursors. */
export interface CursorPaginated<T> {
  data: T[];
  meta: CursorMeta;
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
  "ANALYSIS_NOT_COMPLETED",
  "IDEMPOTENCY_KEY_REUSED",
  "SOURCE_ARCHIVE_INVALID",
  "SOURCE_ARCHIVE_UNSAFE",
  "SOURCE_ARCHIVE_TOO_LARGE",
  "SOURCE_UNCOMPRESSED_SIZE_EXCEEDED",
  "SOURCE_FILE_COUNT_EXCEEDED",
  "SOURCE_FILE_TOO_LARGE",
  "AI_ASSESSMENT_DISABLED",
  "AI_UNAVAILABLE",
  "INSIGHT_EVIDENCE_UNAVAILABLE",
  "INSIGHT_INPUT_TOO_LARGE",
  "ASSESSMENT_EVIDENCE_UNAVAILABLE",
  "ASSESSMENT_INPUT_TOO_LARGE",
  "CHALLENGES_DISABLED",
  "CHALLENGE_NO_ELIGIBLE_GAP",
  "CHALLENGE_NONE_AVAILABLE",
  "CHALLENGE_EVALUATION_UNAVAILABLE",
  "CHALLENGE_EVALUATION_PENDING",
  "CHALLENGE_CLOSED",
  "ROADMAP_NO_SKILL_GAPS",
  "ROADMAP_NO_ACTIONABLE_GAPS",
  "ROADMAP_EVIDENCE_INVALID",
  "ROADMAP_NOT_ACTIVE",
  "ROADMAP_STEP_PREREQUISITES_INCOMPLETE",
  "GITHUB_NOT_CONFIGURED",
  "GITHUB_AUTH_REQUIRED",
  "GITHUB_STATE_INVALID",
  "GITHUB_INSTALLATION_REQUIRED",
  "GITHUB_REPOSITORY_NOT_FOUND",
  "GITHUB_BRANCH_NOT_FOUND",
  "GITHUB_ALREADY_CONNECTED",
  "GITHUB_NOT_CONNECTED",
  "GITHUB_RATE_LIMITED",
  "GITHUB_UNAVAILABLE",
  "PROVIDER_NOT_CONFIGURED",
  "PROVIDER_AUTH_REQUIRED",
  "PROVIDER_STATE_INVALID",
  "PROVIDER_ACCOUNT_IN_USE",
  "PROVIDER_REPOSITORY_NOT_FOUND",
  "PROVIDER_BRANCH_NOT_FOUND",
  "PROVIDER_NOT_CONNECTED",
  "PROVIDER_RATE_LIMITED",
  "PROVIDER_UNAVAILABLE",
  "SOURCE_ALREADY_CONNECTED",
  "FEATURE_NOT_INCLUDED",
  "SUBSCRIPTION_INACTIVE",
  "QUOTA_EXCEEDED",
  "BILLING_UNAVAILABLE",
  "ORGANIZATION_SUSPENDED",
  "ORGANIZATION_ARCHIVED",
  "MEMBERSHIP_SUSPENDED",
  "INSUFFICIENT_ORGANIZATION_ROLE",
  "ALREADY_A_MEMBER",
  "INVITATION_EXPIRED",
  "INVITATION_REVOKED",
  "INVITATION_ALREADY_ACCEPTED",
  "INVITATION_EMAIL_MISMATCH",
  "SEAT_LIMIT_REACHED",
  "CANNOT_REMOVE_OWNER",
  "CANNOT_CHANGE_OWNER_ROLE",
  "REGISTRATION_CLOSED",
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
  /** Phase 24: the owning organization, or null for a personal project. */
  organization_id: string | null;
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

/** `AnalysisRunResource` — GET/POST /api/v1/projects/{project}/analyses. */
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

/* AI assessment (Phase 15): non-authoritative interpretation of a skill gap snapshot. */

export const ASSESSMENT_STATUSES = ["QUEUED", "RUNNING", "SUCCEEDED", "FAILED"] as const;
export type AssessmentStatus = (typeof ASSESSMENT_STATUSES)[number];

export interface AssessmentFailure {
  code: string;
  message: string;
}

/** `AiAssessmentSummaryResource` — GET /api/v1/projects/{project}/assessments (newest first). */
export interface AiAssessmentSummary {
  id: string;
  type: "ai_assessment";
  project_id: string;
  skill_gap_snapshot_id: string;
  status: AssessmentStatus;
  assessment_version: string;
  provider: string;
  model: string;
  failure: AssessmentFailure | null;
  created_at: string | null;
  completed_at: string | null;
}

/** A claim of the interpretation; every one cites evidence ids. */
export interface AssessmentClaim {
  title: string;
  description: string;
  evidence_refs: string[];
}

/** The validated `assessment/v1` output. It never contains a score, level, gap, priority or target. */
export interface AssessmentOutput {
  schema_version: "assessment/v1";
  summary: { text: string; evidence_refs: string[] };
  strengths: AssessmentClaim[];
  areas_to_improve: AssessmentClaim[];
  development_insights: AssessmentClaim[];
  limitations: { description: string; evidence_refs: string[] }[];
}

/** One item of the deterministic evidence the interpretation was built from. */
export interface AssessmentEvidence {
  id: string;
  kind: "quality" | "profile" | "dna" | "component" | "competency" | "gap" | "language";
  label: string;
  description: string;
  facts: Record<string, string | number | boolean | null | string[]>;
}

/** `AiAssessmentResource` — GET/POST /api/v1/projects/{project}/assessments[/{assessment}]. */
export interface AiAssessment {
  id: string;
  type: "ai_assessment";
  project_id: string;
  status: AssessmentStatus;
  notice: string;
  lineage: {
    skill_gap_snapshot_id: string;
    competency_snapshot_id: string;
    dna_snapshot_id: string;
    analysis_run_id: string;
    source_snapshot_id: string;
  };
  versions: {
    assessment: string;
    input_schema: string;
    output_schema: string;
    prompt: string;
    dna_scoring: string;
    competency: string;
    skill_gap: string;
    target_profile: string | null;
    target_profile_version: string | null;
  };
  fingerprints: { specification: string; prompt: string; input: string; output: string | null };
  provider: { name: string; model: string; served_model: string | null };
  attempts: number;
  /** Only when SUCCEEDED. */
  output: AssessmentOutput | null;
  evidence: AssessmentEvidence[];
  failure: AssessmentFailure | null;
  created_at: string | null;
  started_at: string | null;
  completed_at: string | null;
}

export type AiAssessmentResponse = DataEnvelope<AiAssessment>;

/* Coding challenges (Phase 16): a practice layer. Nothing here changes CodeDNA, competencies or skill gaps. */

/** Competencies with challenges (the four of competency version 1.0.0). */
export type CompetencyKey = "COMPLEXITY_MANAGEMENT" | "FUNCTION_DESIGN" | "TYPE_STRUCTURE" | "CODE_HYGIENE";

export const CHALLENGE_STATUSES = ["ASSIGNED", "EVALUATING", "PASSED", "FAILED"] as const;
export type ChallengeStatus = (typeof CHALLENGE_STATUSES)[number];
export type ChallengeDifficulty = "BEGINNER" | "INTERMEDIATE" | "ADVANCED";
export type SubmissionStatus = "QUEUED" | "RUNNING" | "PASSED" | "FAILED" | "ERROR";
export type ChallengeResultStatus = "PASSED" | "FAILED" | "ERROR";

/** `ChallengeSummaryResource` — GET /api/v1/projects/{project}/challenges (newest first). */
export interface ChallengeSummary {
  id: string;
  type: "challenge";
  project_id: string;
  skill_gap_snapshot_id: string;
  competency_key: CompetencyKey;
  definition: { key: string; version: string; title: string | null };
  difficulty: ChallengeDifficulty;
  language: string;
  status: ChallengeStatus;
  attempts_used: number;
  max_attempts: number;
  /** Result of the latest attempt; ERROR attempts consume no attempt. */
  last_result: ChallengeResultStatus | null;
  created_at: string | null;
  closed_at: string | null;
}

export interface ChallengeExample {
  id: string;
  description: string | null;
  args: unknown[];
  expected: unknown;
}

/** The public view of a catalog definition: hidden cases are only counted. */
export interface ChallengeDefinitionView {
  key: string;
  version: string;
  category: CompetencyKey;
  difficulty: ChallengeDifficulty;
  language: string;
  runtime: string;
  estimated_minutes: number;
  title: string;
  summary: string;
  instructions: string[];
  constraints: string[];
  entrypoint: string;
  starter_code: string;
  acceptance_criteria: { id: string; description: string; checks: string[] }[];
  rules: Record<string, number | boolean>;
  examples: ChallengeExample[];
  hidden_case_count: number;
}

/** Why the challenge was selected (deterministic provenance, stored at assignment). */
export interface ChallengeSelectionProvenance {
  selection_version: string;
  catalog_version: string;
  rule: "TOP_PRIORITY_GAP" | "NEXT_ELIGIBLE_GAP" | "REQUESTED_COMPETENCY";
  requested_competency: string | null;
  eligible_gaps: string[];
  gap_rank: number;
  gap: ChallengeGap;
  preferred_difficulty: ChallengeDifficulty;
  selected_difficulty: ChallengeDifficulty;
  challenge_definition: string;
  challenge_version: string;
  excluded_definitions: string[];
  previously_passed: string[];
}

export interface ChallengeGap {
  competency_key: CompetencyKey;
  status: string;
  priority: GapPriority | null;
  priority_capped: boolean | null;
  raw_gap: DecimalString | null;
  current_score: DecimalString | null;
  target_score: DecimalString | null;
}

/** `ChallengeResource` — GET/POST /api/v1/projects/{project}/challenges[/{challenge}]. */
export interface Challenge extends ChallengeSummary {
  notice: string;
  evaluation_available: boolean;
  challenge: ChallengeDefinitionView | null;
  selection: ChallengeSelectionProvenance;
  gap: ChallengeGap | null;
  lineage: { skill_gap_snapshot_id: string; competency_snapshot_id: string; dna_snapshot_id: string; analysis_run_id: string; source_snapshot_id: string };
  versions: { definition: string; catalog: string; selection: string; evaluation: string };
  fingerprints: { catalog: string; definition: string | null; test_suite: string | null };
  recent_attempts: ChallengeSubmissionSummary[];
  updated_at: string | null;
}

export interface ChallengeTestCounts {
  total: number;
  passed: number;
  failed: number;
  visible: { total: number; passed: number };
  hidden: { total: number; passed: number };
}

/** `ChallengeSubmissionSummaryResource`: one attempt without source or feedback. */
export interface ChallengeSubmissionSummary {
  id: string;
  type: "challenge_submission";
  challenge_id: string;
  attempt_number: number;
  language: string;
  status: SubmissionStatus;
  source_bytes: number;
  source_sha256: string;
  tests: ChallengeTestCounts | null;
  execution_status: string | null;
  failure: { code: string; message: string } | null;
  created_at: string | null;
  completed_at: string | null;
}

export interface EvaluatedCase {
  id: string;
  visibility: "VISIBLE" | "HIDDEN";
  status: "PASSED" | "FAILED" | "ERROR" | "NOT_RUN";
  error?: string;
  /** Visible cases only. */
  description?: string | null;
  args?: unknown[];
  expected?: unknown;
  observed?: unknown;
}

export interface EvaluatedRule {
  rule: string;
  limit: number | boolean;
  status: "PASSED" | "FAILED";
  observed?: number;
  line?: number | null;
  not_evaluated?: boolean;
  violations?: { name: string; line: number; value: number }[];
}

/** The deterministic evaluation (challenge-evaluation/1.0.0). Never a CodeDNA score. */
export interface ChallengeEvaluation {
  evaluation_version: string;
  verdict: "PASSED" | "FAILED";
  execution: { status: string; load_error: string | null; message: string };
  tests: ChallengeTestCounts;
  criteria: { id: string; description: string; status: "PASSED" | "FAILED" }[];
  rules: EvaluatedRule[];
  cases: EvaluatedCase[];
}

/** `ChallengeSubmissionResource` — one attempt with its source and feedback (owner only). */
export interface ChallengeSubmission extends ChallengeSubmissionSummary {
  notice: string;
  source: string;
  evaluation: ChallengeEvaluation | null;
  versions: { evaluation: string; evaluator: string | null; runtime: string | null };
  fingerprints: { definition: string; test_suite: string; evaluation: string | null };
  duration_ms: number | null;
  started_at: string | null;
}

export type ChallengeResponse = DataEnvelope<Challenge>;
export type ChallengeSubmissionResponse = DataEnvelope<ChallengeSubmission>;

/*
 * Learning roadmaps (Phase 17; docs/architecture/learning-roadmap-v1.md). A
 * planning layer generated deterministically from a skill gap snapshot.
 * Completing steps is self-reported learning progress: it never changes a
 * score, competency, gap or priority.
 */

export type RoadmapStatus = "ACTIVE" | "COMPLETED" | "SUPERSEDED";
export type RoadmapStepType = "READ" | "PRACTICE" | "CHALLENGE" | "REASSESS";
export type FocusExclusion = "NO_GAP" | "INSUFFICIENT_EVIDENCE" | "UNSUPPORTED" | "MISSING" | "NOT_TARGETED" | "NO_TRACK" | "TRACK_LIMIT";
export type FocusCriterion = "PRIORITY" | "RAW_GAP" | "EVIDENCE_QUALITY" | "COMPETENCY_KEY";

export interface RoadmapProgress {
  completed: number;
  total: number;
}

/** `RoadmapSummaryResource` — GET /api/v1/projects/{project}/roadmaps (newest first). */
export interface RoadmapSummary {
  id: string;
  type: "learning_roadmap";
  project_id: string;
  skill_gap_snapshot_id: string;
  status: RoadmapStatus;
  focus: string[];
  progress: RoadmapProgress;
  estimated_minutes: number;
  versions: { roadmap: string; rules: string };
  created_at: string | null;
  superseded_at: string | null;
  completed_at: string | null;
}

/** A competency of the skill gap snapshot, as stored when the roadmap was generated. */
export interface FocusEntry {
  competency_key: string;
  status: SkillGapStatus;
  priority: GapPriority | null;
  priority_capped: boolean | null;
  current_score: DecimalString | null;
  target_score: DecimalString | null;
  raw_gap: DecimalString | null;
  evidence_quality: DecimalString | null;
  current_level: string | null;
  rank?: number;
  ranked_above?: string | null;
  deciding_criterion?: FocusCriterion | null;
  reason?: FocusExclusion;
}

export interface RoadmapStep {
  key: string;
  position: number;
  type: RoadmapStepType;
  title: string;
  description: string;
  objective: string;
  estimated_minutes: number;
  prerequisites: string[];
  completed_at: string | null;
  can_complete: boolean;
  challenge: { key: string; version: string; title: string; difficulty: ChallengeDifficulty; in_catalog: boolean } | null;
  practice: { challenge_id: string; definition_key: string; status: ChallengeStatus } | null;
}

export interface RoadmapTrack {
  position: number;
  key: string;
  version: string;
  competency_key: string;
  title: string;
  description: string;
  objective: string;
  estimated_minutes: number;
  focus: FocusEntry | null;
  progress: RoadmapProgress;
  steps: RoadmapStep[];
}

/** `RoadmapResource` — one learning roadmap (owner only). */
export interface Roadmap extends RoadmapSummary {
  notice: string;
  development_focus: { selected: FocusEntry[]; excluded: FocusEntry[] };
  tracks: RoadmapTrack[];
  lineage: {
    skill_gap_snapshot_id: string;
    competency_snapshot_id: string;
    dna_snapshot_id: string;
    analysis_run_id: string;
    source_snapshot_id: string;
  };
  versions: { roadmap: string; rules: string; skill_gap: string; target_profile: TargetProfileRef; challenge_catalog: string };
  fingerprints: { roadmap: string; catalog: string; rules: string; skill_gap_specification: string; challenge_catalog: string };
  current: { catalog: boolean; rules: boolean };
  superseded_by: string | null;
  updated_at: string | null;
}

export type RoadmapResponse = DataEnvelope<Roadmap>;

/* ---------------------------------------------------------------- Growth (Phase 18) */

/** NOT_ESTABLISHED: no earlier assessment; INCOMPARABLE: versions differ, nothing compared. */
export type GrowthSnapshotStatus = "NOT_ESTABLISHED" | "INCOMPARABLE" | "COMPARED";
export type GrowthStatus = "IMPROVED" | "REGRESSED" | "UNCHANGED" | "INSUFFICIENT_EVIDENCE";
export type GrowthMetricType = "DNA" | "COMPETENCY" | "SKILL_GAP";
/** The overview state: a growth snapshot status, or why there is none for the newest assessment. */
export type GrowthState = GrowthSnapshotStatus | "NO_ASSESSMENT" | "NOT_CALCULATED";
export type GrowthEventKind = "IMPROVED" | "REGRESSED" | "GAP_CLOSED" | "GAP_OPENED" | "LEVEL_UP" | "LEVEL_DOWN";

export interface GrowthAssessmentRef {
  skill_gap_snapshot_id: string;
  competency_snapshot_id: string;
  dna_snapshot_id: string;
  analysis_run_id: string;
  source_snapshot_id: string;
}

export interface GrowthSummary {
  observations: number;
  statuses: Record<GrowthMetricType, Record<GrowthStatus, number>>;
  level_changes: { UP: number; DOWN: number };
}

export interface GrowthEvent {
  kind: GrowthEventKind;
  metric_type: GrowthMetricType;
  metric_key: string;
  previous_value: DecimalString | null;
  current_value: DecimalString | null;
  /** Signed, e.g. "-0.1100". */
  delta: string | null;
  previous_level?: string | null;
  current_level?: string | null;
}

/** `GrowthSnapshotSummaryResource` — GET /api/v1/projects/{project}/growth/timeline (newest first). */
export interface GrowthSnapshotSummary {
  id: string;
  type: "growth_snapshot";
  project_id: string;
  status: GrowthSnapshotStatus;
  assessed_at: string;
  previous_assessed_at: string | null;
  current: GrowthAssessmentRef;
  previous: GrowthAssessmentRef | null;
  summary: GrowthSummary;
  events: GrowthEvent[];
  rules_version: string;
  created_at: string | null;
}

export interface GrowthObservation {
  metric_key: string;
  better: "HIGHER" | "LOWER";
  previous_state: string;
  current_state: string;
  previous_value: DecimalString | null;
  current_value: DecimalString | null;
  delta: string | null;
  previous_level: string | null;
  current_level: string | null;
  level_change: "UP" | "DOWN" | "SAME" | null;
  previous_evidence_quality: DecimalString | null;
  current_evidence_quality: DecimalString | null;
  status: GrowthStatus;
}

/** `GrowthSnapshotResource` — one growth snapshot (owner only). */
export interface GrowthSnapshot extends GrowthSnapshotSummary {
  notice: string;
  versions: Record<string, string | null>;
  previous_versions: Record<string, string | null> | null;
  /** The version fields that differ (INCOMPARABLE only). */
  differences: string[];
  rules: { version: string; fingerprint: string; current: boolean };
  dna: GrowthObservation[];
  competencies: GrowthObservation[];
  skill_gaps: GrowthObservation[];
  /** Learning activity between the two assessments: context only, never growth evidence. */
  activity: { roadmap_steps_completed: number; challenges_passed: number } | null;
}

export interface GrowthSeries {
  metric_type: GrowthMetricType;
  metric_key: string;
  points: { assessed_at: string | null; value: DecimalString | null }[];
}

/** GET /api/v1/projects/{project}/growth */
export interface GrowthOverview {
  state: GrowthState;
  notice: string;
  latest: GrowthSnapshot | null;
  series: GrowthSeries[];
}

export type GrowthOverviewResponse = DataEnvelope<GrowthOverview>;
export type GrowthSnapshotResponse = DataEnvelope<GrowthSnapshot>;

/* ---------------------------------------------------------------- Historical DNA (Phase 20) */

export type HistoryLayerState = "AVAILABLE" | "UNAVAILABLE";
export type HistorySourceOrigin = "UPLOAD" | "GITHUB" | "REPOSITORY";

export interface HistoryDimension {
  dimension: string;
  name: string;
  /** SCORED, UNAVAILABLE, or MISSING when the snapshot does not contain it. */
  status: string;
  score: DecimalString | null;
  data_quality: DecimalString | null;
}

export interface HistoryCompetency {
  key: string;
  name: string;
  status: string | null;
  score: DecimalString | null;
  level: string | null;
  evidence_quality: DecimalString | null;
}

export interface HistorySkillGap {
  competency_key: string;
  name: string;
  status: string;
  current_score: DecimalString | null;
  target_score: DecimalString | null;
  /** The stored raw gap; null unless GAP or NO_GAP. */
  gap: DecimalString | null;
  material_gap: boolean | null;
  priority: "LOW" | "MEDIUM" | "HIGH" | null;
  evidence_quality: DecimalString | null;
  current_level: string | null;
}

/** `HistoryPointResource` — one stored assessment, as recorded. */
export interface HistoryPoint {
  id: string;
  type: "history_point";
  project_id: string;
  analyzed_at: string;
  analysis_run_id: string;
  layers: { dna: "AVAILABLE"; competency: HistoryLayerState; skill_gaps: HistoryLayerState };
  versions: Record<string, string | null>;
  /** Equal keys: measured alike, per layer. A trend never crosses a change. */
  segments: { dna: string; competency: string | null; skill_gaps: string | null };
  dna: {
    snapshot_id: string;
    status: "READY" | "INSUFFICIENT_DATA";
    overall_score: DecimalString | null;
    data_quality: DecimalString | null;
    scoring_version: string;
    specification_fingerprint: string | null;
    metrics_version: string | null;
    created_at: string | null;
    dimensions: HistoryDimension[];
  };
  competency: {
    snapshot_id: string;
    status: string;
    competency_version: string;
    specification_fingerprint: string;
    created_at: string | null;
    competencies: HistoryCompetency[];
  } | null;
  skill_gaps: {
    snapshot_id: string;
    status: string;
    skill_gap_version: string;
    target_profile: string;
    target_profile_version: string;
    specification_fingerprint: string;
    created_at: string | null;
    results: HistorySkillGap[];
  } | null;
  source: {
    snapshot_id: string;
    version: number;
    origin: HistorySourceOrigin;
    source_hash: string;
    file_count: number;
    primary_language: string | null;
    created_at: string | null;
    github: { repository: string | null; ref: string | null; commit_sha: string | null } | null;
  };
  /** The assessment's stored Phase 18 growth, when calculated. */
  growth: {
    id: string;
    status: GrowthSnapshotStatus;
    previous_dna_snapshot_id: string | null;
    previous_assessed_at: string | null;
    rules_version: string;
    summary: GrowthSummary;
    events: GrowthEvent[];
  } | null;
}

export interface HistoryRef {
  dna_snapshot_id: string;
  analyzed_at: string;
}

export type LearningActivityContext = { roadmap_steps_completed: number; challenges_passed: number };

/** GET /api/v1/projects/{project}/history/{dnaSnapshot} */
export interface HistoryPointDetail extends HistoryPoint {
  notice: string;
  previous: HistoryRef | null;
  next: HistoryRef | null;
  /** Since the previous point: context only, never evidence. */
  activity: LearningActivityContext | null;
}

export type HistoryComparisonLayer = "COMPARED" | "INCOMPARABLE" | "UNAVAILABLE";

/** GET /api/v1/projects/{project}/history/compare?from=&to= */
export interface HistoryComparison {
  type: "history_comparison";
  notice: string;
  status: "COMPARED" | "INCOMPARABLE";
  /** GROWTH_SNAPSHOT: the stored Phase 18 growth; GROWTH_RULES: the Phase 18 rules over stored values. */
  basis: "GROWTH_SNAPSHOT" | "GROWTH_RULES";
  growth_snapshot_id: string | null;
  rules: { version: string; fingerprint: string };
  from: HistoryPoint;
  to: HistoryPoint;
  layers: { dna: HistoryComparisonLayer; competency: HistoryComparisonLayer; skill_gaps: HistoryComparisonLayer };
  differences: string[];
  summary: GrowthSummary;
  events: GrowthEvent[];
  dna: GrowthObservation[];
  competencies: GrowthObservation[];
  skill_gaps: GrowthObservation[];
  activity: LearningActivityContext;
}

export type HistoryPointResponse = DataEnvelope<HistoryPointDetail>;
export type HistoryComparisonResponse = DataEnvelope<HistoryComparison>;

/* ---------------------------------------------------------------- GitHub (Phase 19) */

/** GET /api/v1/github — the signed-in user's GitHub authorization. Never a token. */
export interface GitHubAccountStatus {
  configured: boolean;
  account: { login: string; connected_at: string | null } | null;
}

/** POST /api/v1/github/authorizations — where to send the browser (GitHub only). */
export interface GitHubAuthorizationStart {
  authorize_url: string;
  install_url: string;
  expires_at: string;
}

export interface GitHubInstallation {
  id: number;
  account: string;
  account_type: "User" | "Organization";
  repository_selection: "all" | "selected";
}

/** Repository metadata as verified with GitHub. */
export interface GitHubRepository {
  id: number;
  owner: string;
  name: string;
  full_name: string;
  private: boolean;
  archived: boolean;
  default_branch: string;
}

export interface GitHubBranch {
  name: string;
  protected: boolean;
}

/** A page of GitHub items: GitHub does not say how many exist, only whether more do. */
export interface GitHubPage<T> {
  data: T[];
  meta: { page: number; per_page: number; has_more: boolean; default_branch?: string };
}

export type GitHubConnectionStatus = "ACTIVE" | "DISCONNECTED";
export type GitHubImportStatus = "QUEUED" | "RUNNING" | "SUCCEEDED" | "FAILED" | "CANCELLED";

/** `GitHubConnectionResource` */
export interface GitHubConnection {
  id: string;
  type: "github_connection";
  project_id: string;
  status: GitHubConnectionStatus;
  repository: GitHubRepository;
  branch: string;
  last_imported_commit_sha: string | null;
  last_imported_at: string | null;
  metadata_verified_at: string;
  connected_at: string;
  disconnected_at: string | null;
}

/** `GitHubImportResource` */
export interface GitHubImport {
  id: string;
  type: "github_import";
  project_id: string;
  status: GitHubImportStatus;
  repository: string;
  ref: string;
  commit_sha: string | null;
  failure_code: string | null;
  source_snapshot: { id: string; version: number } | null;
  created_snapshot: boolean;
  created_at: string | null;
  started_at: string | null;
  completed_at: string | null;
}

/** GET /api/v1/projects/{project}/github */
export interface ProjectGitHub {
  configured: boolean;
  account_connected: boolean;
  connection: GitHubConnection | null;
  latest_import: GitHubImport | null;
}

/* ------------------------------------------- GitLab and Bitbucket Cloud (Phase 28) */
// backend/app/Http/Controllers/Api/V1/{Repositories/RepositoryProviderAccountController,Projects/ProjectRepositoryProviderController}.php

export const REPOSITORY_PROVIDERS = ["gitlab", "bitbucket"] as const;
export type RepositoryProviderKey = (typeof REPOSITORY_PROVIDERS)[number];

/** GET /api/v1/repository-providers — one entry per provider. Never a token. */
export interface RepositoryProviderAccountStatus {
  provider: RepositoryProviderKey;
  name: string;
  configured: boolean;
  /** The configured installation's host (GitLab.com or a self-managed instance). */
  host: string | null;
  account: { username: string; connected_at: string | null } | null;
}

/** POST /api/v1/repository-providers/{provider}/authorizations */
export interface RepositoryProviderAuthorizationStart {
  authorize_url: string;
  expires_at: string;
}

/** DELETE /api/v1/repository-providers/{provider}: tokens are always deleted; revocation is best effort. */
export interface RepositoryProviderUnlink {
  provider: RepositoryProviderKey;
  revocation: "REVOKED" | "NOT_SUPPORTED" | "FAILED" | "NOT_LINKED";
}

/** Repository metadata as verified with the provider. */
export interface ProviderRepository {
  id: string;
  full_name: string;
  private: boolean;
  archived: boolean;
  default_branch: string;
}

/** `RepositoryProviderConnectionResource` */
export interface RepositoryProviderConnection {
  id: string;
  type: "repository_provider_connection";
  project_id: string;
  provider: RepositoryProviderKey;
  status: GitHubConnectionStatus;
  repository: ProviderRepository;
  branch: string;
  last_imported_commit_sha: string | null;
  last_imported_at: string | null;
  connected_at: string;
  disconnected_at: string | null;
}

/** `RepositoryProviderImportResource`: the same shape as a GitHub import. */
export interface RepositoryProviderImport extends Omit<GitHubImport, "type"> {
  type: "repository_provider_import";
  provider: RepositoryProviderKey;
}

/** GET /api/v1/projects/{project}/repository-provider */
export interface ProjectRepositoryProvider {
  providers: { provider: RepositoryProviderKey; name: string; configured: boolean; account_connected: boolean }[];
  /** A project has one repository source: GitHub or one of these. */
  github_connected: boolean;
  connection: RepositoryProviderConnection | null;
  latest_import: RepositoryProviderImport | null;
}

// Billing (Phase 23): BillingController, Billing*Resource. Read-only: the
// browser shows what the server decided and never grants anything.

export const BILLING_FEATURES = [
  "PROJECTS",
  "SOURCE_ANALYSIS",
  "AI_ASSESSMENT",
  "CODING_CHALLENGES",
  "LEARNING_ROADMAP",
  "GROWTH_ANALYTICS",
  "HISTORICAL_DNA",
  "GITHUB_INTEGRATION",
] as const;
export type BillingFeature = (typeof BILLING_FEATURES)[number];

export const SUBSCRIPTION_STATUSES = ["TRIALING", "ACTIVE", "PAST_DUE", "PAUSED", "CANCELED", "EXPIRED"] as const;
export type SubscriptionStatus = (typeof SUBSCRIPTION_STATUSES)[number];

export interface BillingPlanRef {
  key: string;
  version: string;
  name: string;
}

/** `BillingSubscriptionResource` (never carries provider references). */
export interface BillingSubscription {
  id: string;
  type: "billing_subscription";
  status: SubscriptionStatus;
  provider: string;
  plan: BillingPlanRef;
  grants_access: boolean;
  started_at: string;
  current_period_start: string;
  current_period_end: string;
  trial_ends_at: string | null;
  cancel_at_period_end: boolean;
  canceled_at: string | null;
  ended_at: string | null;
}

export interface BillingQuota {
  key: string;
  label: string;
  unit: "COUNT" | "BYTES";
  period: "MONTHLY" | "CURRENT";
  /** null: unlimited. */
  limit: number | null;
  used: number;
  remaining: number | null;
  unlimited: boolean;
  resets_at: string | null;
}

/** GET /api/v1/billing */
export interface BillingOverview {
  type: "billing_overview";
  plan: BillingPlanRef;
  source: "FREE_FALLBACK" | "SUBSCRIPTION";
  status: SubscriptionStatus | "FREE";
  subscription: BillingSubscription | null;
  inactive_subscription: BillingSubscription | null;
  period: { start: string; end: string };
  entitlements: { feature: BillingFeature; label: string; included: boolean }[];
  quotas: BillingQuota[];
}

/** `BillingPlanResource`. Prices are integer minor units (cents); null is "not offered". */
export interface BillingPlan {
  type: "billing_plan";
  key: string;
  version: string;
  name: string;
  description: string;
  status: "ACTIVE" | "RESERVED" | "RETIRED";
  available: boolean;
  currency: string;
  prices: { monthly_minor: number | null; annual_minor: number | null };
  features: { key: BillingFeature; label: string; included: boolean }[];
  quotas: { key: string; label: string; unit: "COUNT" | "BYTES"; period: "MONTHLY" | "CURRENT"; limit: number | null }[];
}

// Organizations (Phase 24): OrganizationResource and friends. Roles and
// statuses are the server's; the UI shows controls only where the server
// would allow them, and the server decides every request.

export const ORGANIZATION_ROLES = ["OWNER", "ADMIN", "MEMBER"] as const;
export type OrganizationRole = (typeof ORGANIZATION_ROLES)[number];
export type OrganizationStatus = "ACTIVE" | "SUSPENDED" | "ARCHIVED";
export type MembershipStatus = "ACTIVE" | "SUSPENDED" | "REMOVED";
export type InvitationStatus = "PENDING" | "ACCEPTED" | "REVOKED" | "EXPIRED";

export interface Organization {
  id: string;
  type: "organization";
  name: string;
  slug: string;
  status: OrganizationStatus;
  /** The caller's own role and membership status. */
  role: OrganizationRole;
  membership_status: MembershipStatus;
  member_count: number;
  project_count: number;
  created_at: string;
  updated_at: string;
}

export interface OrganizationMembership {
  id: string;
  type: "organization_membership";
  /** email is null unless the caller is an ADMIN or the OWNER. */
  user: { id: string; name: string; email: string | null };
  role: OrganizationRole;
  status: MembershipStatus;
  joined_at: string;
  updated_at: string;
}

export interface OrganizationInvitation {
  id: string;
  type: "organization_invitation";
  email: string;
  role: Exclude<OrganizationRole, "OWNER">;
  status: InvitationStatus;
  invited_by: { id: string; name: string };
  expires_at: string;
  accepted_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

/** POST .../invitations: the invitation and, once, its token. */
export interface CreatedInvitation extends OrganizationInvitation {
  token: string;
}

export interface InvitationPreview {
  type: "organization_invitation_preview";
  status: InvitationStatus;
  /** Only while the invitation is PENDING. */
  organization: { name: string } | null;
  role: Exclude<OrganizationRole, "OWNER"> | null;
  expires_at: string | null;
  email_hint: string | null;
}

export interface InvitationAcceptance {
  type: "organization_invitation_acceptance";
  organization: Organization;
  membership: OrganizationMembership;
}

export interface OrganizationAuditEvent {
  id: string;
  type: "organization_audit_event";
  action: string;
  actor: { id: string; name: string | null } | null;
  target: { type: string; id: string } | null;
  metadata: Record<string, unknown>;
  request_id: string | null;
  created_at: string;
}

export interface OrganizationBilling {
  type: "organization_billing";
  plan: BillingPlanRef;
  entitlement_version: string;
  /** Phase 27: a valid enterprise license sets every organization's plan and seat limit. */
  edition: Edition;
  plan_source: "BILLING_ACCOUNT" | "ENTERPRISE_LICENSE";
  seats: { limit: number; used: number; remaining: number };
  period: { start: string; end: string };
  quotas: BillingQuota[];
}

export interface CompetencyAggregate {
  key: string;
  measured_projects: number;
  sufficient: boolean;
  average_score: number | null;
  gap_projects: number;
  priorities: { HIGH: number; MEDIUM: number; LOW: number };
  insufficient_evidence_projects: number;
}

/** GET .../analytics: read-only, deterministic, grouped by version. */
export interface OrganizationAnalytics {
  type: "organization_analytics";
  organization_id: string;
  generated_at: string;
  minimum_projects: number;
  members: { active: number; suspended: number; by_role: Record<OrganizationRole, number> };
  seats: { limit: number; used: number; remaining: number };
  projects: { active: number; archived: number };
  analyses: { succeeded: number; failed: number; succeeded_last_30_days: number; last_completed_at: string | null };
  dna: {
    projects_with_dna: number;
    members_with_dna: number;
    by_version: {
      scoring_version: string;
      projects: number;
      scored_projects: number;
      sufficient: boolean;
      average_overall_score: number | null;
    }[];
  };
  competencies: {
    competency_version: string;
    skill_gap_version: string;
    target_profile: string;
    target_profile_version: string;
    projects: number;
    snapshot_status: Record<string, number>;
    competencies: CompetencyAggregate[];
  }[];
}

/** Phase 27 (docs/enterprise/enterprise-architecture.md). Informational: entitlements are decided by the server. */
export type Edition = "COMMUNITY" | "ENTERPRISE";

export type LicenseStatus =
  | "ABSENT"
  | "VALID"
  | "UNREADABLE"
  | "MALFORMED"
  | "UNSUPPORTED_VERSION"
  | "UNKNOWN_KEY"
  | "INVALID_SIGNATURE"
  | "WRONG_INSTALLATION"
  | "NOT_YET_VALID"
  | "EXPIRED";

/** GET /api/v1/installation. */
export interface Installation {
  type: "installation";
  edition: Edition;
  license: {
    status: LicenseStatus;
    licensee: string | null;
    expires_at: string | null;
    organization_plan: string | null;
    organization_seats: number | null;
  };
  registration: { mode: "open" | "restricted" | "closed" };
}

// AI insights (Phase 29): InsightController, AiInsightResource, AiStatusController.
// Non-authoritative, evidence-grounded interpretations generated by the local
// AI gateway. The browser names a kind and a subject only.

export const INSIGHT_KINDS = ["GROWTH_INTERPRETATION", "ROADMAP_GUIDANCE", "CHALLENGE_FEEDBACK"] as const;
export type InsightKind = (typeof INSIGHT_KINDS)[number];

export interface InsightClaim {
  title: string;
  description: string;
  evidence_refs: string[];
}

/** The validated `insight/v1` output. It never contains a score, level, verdict or measurement of its own. */
export interface InsightOutput {
  schema_version: "insight/v1";
  summary: { text: string; evidence_refs: string[] };
  points: InsightClaim[];
  next_steps: InsightClaim[];
  limitations: { description: string; evidence_refs: string[] }[];
}

/** One item of the deterministic evidence an insight was built from. */
export interface InsightEvidence {
  id: string;
  kind: string;
  label: string;
  description: string;
  facts: Record<string, string | number | boolean | null | string[]>;
}

/** `AiInsightResource` — GET/POST /api/v1/projects/{project}/insights[/{insight}]. */
export interface AiInsight {
  id: string;
  type: "ai_insight";
  project_id: string;
  kind: InsightKind;
  subject_id: string;
  status: AssessmentStatus;
  notice: string;
  versions: { insight: string; input_schema: string; output_schema: string; prompt: string };
  fingerprints: { specification: string; prompt: string; input: string; output: string | null };
  provider: { name: string; model: string; served_model: string | null };
  /** Reported by the runtime when it reports them; null means unknown. */
  usage: { input_tokens: number | null; output_tokens: number | null; duration_ms: number | null };
  attempts: number;
  output: InsightOutput | null;
  evidence: InsightEvidence[];
  failure: AssessmentFailure | null;
  created_at: string | null;
  started_at: string | null;
  completed_at: string | null;
}

/** GET /api/v1/ai/status — whether AI explanations can be requested now. Never a URL or key. */
export interface AiStatus {
  enabled: boolean;
  available: boolean;
  provider: string | null;
  endpoint: "local" | "remote" | "fake" | null;
  model: string | null;
  reachable: boolean | null;
  model_available: boolean | null;
}
