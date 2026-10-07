import type { GitHubImportStatus } from "@/lib/api/types";

/** Display helpers for the GitHub integration. Labels only. */

const STATUS: Record<GitHubImportStatus, string> = {
  QUEUED: "Waiting to start",
  RUNNING: "Importing",
  SUCCEEDED: "Imported",
  FAILED: "Failed",
  CANCELLED: "Cancelled",
};

const FAILURES: Record<string, string> = {
  GITHUB_NOT_CONFIGURED: "GitHub integration is not configured on this server.",
  GITHUB_AUTH_REQUIRED: "Your GitHub authorization is no longer valid. Connect GitHub again.",
  GITHUB_INSTALLATION_REQUIRED: "The CodeDNA GitHub App is no longer installed for this repository.",
  GITHUB_REPOSITORY_NOT_FOUND: "The repository is no longer accessible through the CodeDNA GitHub App.",
  GITHUB_BRANCH_NOT_FOUND: "The branch no longer exists. Choose another branch; CodeDNA never switches branches on its own.",
  GITHUB_RATE_LIMITED: "GitHub was rate limiting requests. Try the import again later.",
  GITHUB_UNAVAILABLE: "GitHub was not reachable. Try the import again later.",
  GITHUB_IMPORT_FAILED: "The import could not be completed. Try again.",
  SOURCE_ARCHIVE_INVALID: "GitHub's archive was not a valid ZIP of this commit.",
  SOURCE_ARCHIVE_UNSAFE: "The repository contains an entry CodeDNA does not accept (for example a symbolic link).",
  SOURCE_ARCHIVE_TOO_LARGE: "The repository archive is larger than the source limit.",
  SOURCE_UNCOMPRESSED_SIZE_EXCEEDED: "The repository expands beyond the allowed total size.",
  SOURCE_FILE_COUNT_EXCEEDED: "The repository contains more files than allowed.",
  SOURCE_FILE_TOO_LARGE: "The repository contains a file larger than allowed.",
  PROJECT_ARCHIVED: "The project was archived before the import finished.",
};

export const importStatusLabel = (status: GitHubImportStatus): string => STATUS[status];

/** What a stored failure code means; unknown codes get a generic sentence, never raw text. */
export const importFailureMessage = (code: string | null): string => (code !== null && code in FAILURES ? FAILURES[code] : FAILURES.GITHUB_IMPORT_FAILED);

/** First 7 characters, as GitHub shows commits. */
export const shortSha = (sha: string | null): string => (sha === null ? "—" : sha.slice(0, 7));

/**
 * The authorization URLs come from the server (configured GitHub origin, HTTPS
 * enforced in production). Defense in depth: only http(s) URLs are followed,
 * never javascript:, data: or anything else.
 */
export function isSafeRedirect(url: string): boolean {
  try {
    const parsed = new URL(url);
    return parsed.protocol === "https:" || parsed.protocol === "http:";
  } catch {
    return false;
  }
}
