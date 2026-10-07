import type { ChallengeEvaluation, ChallengeSubmission } from "@/lib/api/types";
import { formatValue, ruleLabel, ruleLimit, submissionStatusLabel } from "@/lib/challenge/format";

/**
 * Deterministic feedback for one attempt: verdict, execution, acceptance
 * criteria, code-structure rules and test cases. Visible cases show
 * expected and observed values; hidden cases show only their status. It
 * says nothing about the developer and nothing about CodeDNA.
 */
export function EvaluationFeedback({ submission }: { submission: ChallengeSubmission }) {
  if (submission.status === "QUEUED" || submission.status === "RUNNING") {
    return (
      <div className="rounded-lg border p-4 text-sm" role="status" aria-live="polite" data-testid="feedback-pending">
        Attempt {submission.attempt_number}: {submissionStatusLabel(submission.status)}. This page updates automatically.
      </div>
    );
  }
  if (submission.status === "ERROR" || submission.evaluation === null) {
    return (
      <div className="rounded-lg border border-dashed p-4 text-sm" data-testid="feedback-error">
        Attempt {submission.attempt_number} was not evaluated. {submission.failure?.message ?? "The evaluation could not be completed."}
      </div>
    );
  }
  return <Evaluation evaluation={submission.evaluation} attempt={submission.attempt_number} />;
}

function Evaluation({ evaluation, attempt }: { evaluation: ChallengeEvaluation; attempt: number }) {
  const passed = evaluation.verdict === "PASSED";

  return (
    <div className="grid gap-4" data-testid="feedback">
      <div
        className={`rounded-lg border p-4 ${passed ? "border-emerald-300 bg-emerald-50 text-emerald-950" : "border-rose-300 bg-rose-50 text-rose-950"}`}
        data-testid="feedback-verdict"
      >
        <p className="font-medium">
          Attempt {attempt}: {passed ? "all acceptance criteria are met." : "not all acceptance criteria are met yet."}
        </p>
        <p className="text-sm">
          {evaluation.execution.message}
          {evaluation.execution.load_error ? ` (${evaluation.execution.load_error})` : ""} Tests passed: {evaluation.tests.passed} of{" "}
          {evaluation.tests.total} ({evaluation.tests.visible.passed} of {evaluation.tests.visible.total} examples,{" "}
          {evaluation.tests.hidden.passed} of {evaluation.tests.hidden.total} hidden).
        </p>
      </div>

      <section aria-labelledby="feedback-criteria" className="grid gap-2">
        <h3 id="feedback-criteria" className="font-medium">
          Acceptance criteria
        </h3>
        <ul className="grid gap-1 text-sm">
          {evaluation.criteria.map((criterion) => (
            <li key={criterion.id} data-testid={`criterion-${criterion.id}`}>
              <span aria-hidden="true">{criterion.status === "PASSED" ? "✓" : "✗"}</span> {criterion.id}: {criterion.description}{" "}
              <span className="sr-only">{criterion.status === "PASSED" ? "met" : "not met"}</span>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="feedback-rules" className="grid gap-2">
        <h3 id="feedback-rules" className="font-medium">
          Code structure
        </h3>
        <ul className="grid gap-1 text-sm">
          {evaluation.rules.map((rule) => (
            <li key={rule.rule} data-testid={`rule-${rule.rule}`}>
              <span aria-hidden="true">{rule.status === "PASSED" ? "✓" : "✗"}</span> {ruleLabel(rule.rule)}: {ruleLimit(rule.rule, rule.limit)}
              {rule.not_evaluated ? " — not checked because the file does not parse" : ""}
              {rule.line ? ` — syntax error on line ${rule.line}` : ""}
              {rule.observed !== undefined && !rule.not_evaluated ? ` — measured ${rule.observed}` : ""}
              {rule.violations && rule.violations.length > 0 ? (
                <ul className="text-muted-foreground ml-6 list-disc">
                  {rule.violations.map((v) => (
                    <li key={`${v.name}-${v.line}`}>
                      {v.name} (line {v.line}): {v.value}
                    </li>
                  ))}
                </ul>
              ) : null}
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="feedback-cases" className="grid gap-2">
        <h3 id="feedback-cases" className="font-medium">
          Test cases
        </h3>
        <ul className="grid gap-2 text-sm">
          {evaluation.cases.map((c) => (
            <li key={c.id} className="rounded-lg border p-2" data-testid={`case-${c.id}`}>
              <span className="font-medium">
                {c.visibility === "VISIBLE" ? `Example ${c.id}` : `Hidden test ${c.id}`}: {c.status.replace("_", " ").toLowerCase()}
              </span>
              {c.error ? <span> — raised {c.error}</span> : null}
              {c.visibility === "VISIBLE" ? (
                <div className="text-muted-foreground grid gap-0.5 font-mono text-xs">
                  {c.description ? <span className="font-sans">{c.description}</span> : null}
                  <span>arguments: {formatValue(c.args)}</span>
                  <span>expected: {formatValue(c.expected)}</span>
                  {c.status === "FAILED" ? <span>observed: {formatValue(c.observed)}</span> : null}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
