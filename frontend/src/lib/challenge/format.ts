import type { ChallengeDifficulty, ChallengeStatus, CompetencyKey, SubmissionStatus } from "@/lib/api/types";

/** Display helpers for coding challenges. Labels only; nothing is computed. */

const STATUS: Record<ChallengeStatus, string> = {
  ASSIGNED: "Open",
  EVALUATING: "Evaluating",
  PASSED: "Passed",
  FAILED: "Attempts used up",
};

const SUBMISSION: Record<SubmissionStatus, string> = {
  QUEUED: "Waiting for evaluation",
  RUNNING: "Evaluating",
  PASSED: "Passed",
  FAILED: "Not passed",
  ERROR: "Not evaluated",
};

const DIFFICULTY: Record<ChallengeDifficulty, string> = {
  BEGINNER: "Beginner exercise",
  INTERMEDIATE: "Intermediate exercise",
  ADVANCED: "Advanced exercise",
};

const COMPETENCY: Record<CompetencyKey, string> = {
  COMPLEXITY_MANAGEMENT: "Complexity management",
  FUNCTION_DESIGN: "Function design",
  TYPE_STRUCTURE: "Type structure",
  CODE_HYGIENE: "Code hygiene",
};

const RULES: Record<string, string> = {
  syntax_valid: "The file has no syntax errors",
  max_function_complexity: "Cyclomatic complexity per function",
  max_function_nesting: "Nesting depth per function",
  max_function_lines: "Lines per function",
  max_function_parameters: "Parameters per function",
  max_class_lines: "Lines per class",
  max_class_methods: "Methods per class",
  min_classes: "Number of classes",
};

const SELECTION_RULES: Record<string, string> = {
  TOP_PRIORITY_GAP: "It addresses the skill gap with the highest priority in this analysis.",
  NEXT_ELIGIBLE_GAP: "Exercises for higher-ranked gaps were already assigned, so it addresses the next gap in priority order.",
  REQUESTED_COMPETENCY: "It addresses the competency that was requested.",
};

export const challengeStatusLabel = (status: ChallengeStatus): string => STATUS[status];
export const submissionStatusLabel = (status: SubmissionStatus): string => SUBMISSION[status];
export const difficultyLabel = (difficulty: ChallengeDifficulty): string => DIFFICULTY[difficulty];
export const competencyName = (key: string): string => COMPETENCY[key as CompetencyKey] ?? key;
export const ruleLabel = (rule: string): string => RULES[rule] ?? rule.replaceAll("_", " ");
export const selectionRuleLabel = (rule: string): string => SELECTION_RULES[rule] ?? rule;

/** A JSON value as compact text, for expected and observed values. */
export function formatValue(value: unknown): string {
  return JSON.stringify(value) ?? "undefined";
}

/** "at most 15" / "at least 1" / "required". */
export function ruleLimit(rule: string, limit: number | boolean): string {
  if (typeof limit === "boolean") return "required";
  return rule.startsWith("min_") ? `at least ${limit}` : `at most ${limit}`;
}
