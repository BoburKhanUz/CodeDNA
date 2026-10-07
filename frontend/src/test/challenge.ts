import type { Challenge, ChallengeSubmission, ChallengeSummary } from "@/lib/api/types";

import { COMPETENCY_ID } from "./competency";
import { DNA_ID, RUN_ID, SOURCE_ID } from "./dna";
import { project } from "./responses";
import { SKILL_GAP_ID } from "./skill-gap";

/** Challenge responses shaped exactly like the Laravel API's (ChallengeResource, ChallengeSubmissionResource). */

export const CHALLENGE_ID = "01k6p0a1b2c3d4e5f6g7h8j9ch";
export const SUBMISSION_ID = "01k6p0a1b2c3d4e5f6g7h8j9sb";

const NOTICE = "Completing this challenge does not immediately change your CodeDNA score or skill gap. Reassessment occurs from new code analysis.";

export function challengeSummary(overrides: Partial<ChallengeSummary> = {}): ChallengeSummary {
  return {
    id: CHALLENGE_ID,
    type: "challenge",
    project_id: project.id,
    skill_gap_snapshot_id: SKILL_GAP_ID,
    competency_key: "CODE_HYGIENE",
    definition: { key: "CODE_HYGIENE_001", version: "1.0.0", title: "Repair the configuration parser" },
    difficulty: "BEGINNER",
    language: "python",
    status: "ASSIGNED",
    attempts_used: 0,
    max_attempts: 5,
    last_result: null,
    created_at: "2026-10-12T08:00:00Z",
    closed_at: null,
    ...overrides,
  };
}

export function challenge(overrides: Partial<Challenge> = {}): Challenge {
  return {
    ...challengeSummary(),
    notice: NOTICE,
    evaluation_available: true,
    challenge: {
      key: "CODE_HYGIENE_001",
      version: "1.0.0",
      category: "CODE_HYGIENE",
      difficulty: "BEGINNER",
      language: "python",
      runtime: "python3.11",
      estimated_minutes: 15,
      title: "Repair the configuration parser",
      summary: "Fix the syntax errors in a small parser so that the file is valid and behaves as specified.",
      instructions: ["The starter file does not parse. Fix it so that it is valid Python 3.11.", "parse_config(text) reads 'key = value' lines into a dictionary."],
      constraints: ["Python 3.11 standard library only.", "The file has no syntax errors."],
      entrypoint: "parse_config",
      starter_code: "def parse_config(text)\n    return {}\n",
      acceptance_criteria: [
        { id: "AC1", description: "The file is valid Python with no syntax errors.", checks: ["rule:syntax_valid"] },
        { id: "AC2", description: "The examples parse as expected.", checks: ["tests:visible"] },
        { id: "AC3", description: "All further inputs parse as expected.", checks: ["tests:hidden"] },
      ],
      rules: { syntax_valid: true },
      examples: [{ id: "v1", description: "two settings", args: ["name = CodeDNA\nversion=1"], expected: { name: "CodeDNA", version: "1" } }],
      hidden_case_count: 3,
    },
    selection: {
      selection_version: "challenge-selection/1.0.0",
      catalog_version: "1.0.0",
      rule: "TOP_PRIORITY_GAP",
      requested_competency: null,
      eligible_gaps: ["CODE_HYGIENE"],
      gap_rank: 1,
      gap: { competency_key: "CODE_HYGIENE", status: "GAP", priority: "HIGH", priority_capped: false, raw_gap: "0.3000", current_score: "0.6000", target_score: "0.9000" },
      preferred_difficulty: "BEGINNER",
      selected_difficulty: "BEGINNER",
      challenge_definition: "CODE_HYGIENE_001",
      challenge_version: "1.0.0",
      excluded_definitions: [],
      previously_passed: [],
    },
    gap: { competency_key: "CODE_HYGIENE", status: "GAP", priority: "HIGH", priority_capped: false, raw_gap: "0.3000", current_score: "0.6000", target_score: "0.9000" },
    lineage: { skill_gap_snapshot_id: SKILL_GAP_ID, competency_snapshot_id: COMPETENCY_ID, dna_snapshot_id: DNA_ID, analysis_run_id: RUN_ID, source_snapshot_id: SOURCE_ID },
    versions: { definition: "1.0.0", catalog: "1.0.0", selection: "challenge-selection/1.0.0", evaluation: "challenge-evaluation/1.0.0" },
    fingerprints: { catalog: "a".repeat(64), definition: "b".repeat(64), test_suite: "c".repeat(64) },
    recent_attempts: [],
    updated_at: "2026-10-12T08:00:00Z",
    ...overrides,
  };
}

export function submission(overrides: Partial<ChallengeSubmission> = {}): ChallengeSubmission {
  return {
    id: SUBMISSION_ID,
    type: "challenge_submission",
    challenge_id: CHALLENGE_ID,
    attempt_number: 1,
    language: "python",
    status: "FAILED",
    source_bytes: 40,
    source_sha256: "d".repeat(64),
    tests: { total: 5, passed: 3, failed: 2, visible: { total: 2, passed: 1 }, hidden: { total: 3, passed: 2 } },
    execution_status: "COMPLETED",
    failure: null,
    created_at: "2026-10-12T08:05:00Z",
    completed_at: "2026-10-12T08:05:02Z",
    notice: NOTICE,
    source: "def parse_config(text):\n    return {}\n",
    evaluation: {
      evaluation_version: "challenge-evaluation/1.0.0",
      verdict: "FAILED",
      execution: { status: "COMPLETED", load_error: null, message: "The code ran to completion." },
      tests: { total: 5, passed: 3, failed: 2, visible: { total: 2, passed: 1 }, hidden: { total: 3, passed: 2 } },
      criteria: [
        { id: "AC1", description: "The file is valid Python with no syntax errors.", status: "PASSED" },
        { id: "AC2", description: "The examples parse as expected.", status: "FAILED" },
        { id: "AC3", description: "All further inputs parse as expected.", status: "PASSED" },
      ],
      rules: [{ rule: "syntax_valid", limit: true, status: "PASSED", line: null }],
      cases: [
        { id: "v1", visibility: "VISIBLE", status: "FAILED", description: "two settings", args: ["name = CodeDNA\nversion=1"], expected: { name: "CodeDNA", version: "1" }, observed: {} },
        { id: "v2", visibility: "VISIBLE", status: "PASSED", description: null, args: ["# c"], expected: {} },
        { id: "h1", visibility: "HIDDEN", status: "PASSED" },
        { id: "h2", visibility: "HIDDEN", status: "ERROR", error: "KeyError" },
      ],
    },
    versions: { evaluation: "challenge-evaluation/1.0.0", evaluator: "1.0.0", runtime: "python3.11" },
    fingerprints: { definition: "b".repeat(64), test_suite: "c".repeat(64), evaluation: "e".repeat(64) },
    duration_ms: 120,
    started_at: "2026-10-12T08:05:00Z",
    ...overrides,
  };
}

export function summaryOf(s: ChallengeSubmission) {
  const { id, type, challenge_id, attempt_number, language, status, source_bytes, source_sha256, tests, execution_status, failure, created_at, completed_at } = s;
  return { id, type, challenge_id, attempt_number, language, status, source_bytes, source_sha256, tests, execution_status, failure, created_at, completed_at };
}
