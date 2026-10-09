/** Display helpers for GitLab and Bitbucket Cloud (Phase 28). Labels only. */

const FAILURES: Record<string, string> = {
  PROVIDER_AUTH_REQUIRED: "Your authorization with the provider is missing or no longer valid. Connect your account again.",
  PROVIDER_REPOSITORY_NOT_FOUND: "The repository is no longer accessible with your account.",
  PROVIDER_BRANCH_NOT_FOUND: "The branch no longer exists. Choose another branch; CodeDNA never switches branches on its own.",
  PROVIDER_RATE_LIMITED: "The provider was rate limiting requests. Try the import again later.",
  PROVIDER_UNAVAILABLE: "The provider was not reachable. Try the import again later.",
  PROVIDER_IMPORT_FAILED: "The import could not be completed. Try again.",
  SOURCE_ARCHIVE_INVALID: "The provider's archive was not a valid ZIP of this commit.",
  SOURCE_ARCHIVE_UNSAFE: "The repository contains an entry CodeDNA does not accept (for example a symbolic link).",
  SOURCE_ARCHIVE_TOO_LARGE: "The repository archive is larger than the source limit.",
  SOURCE_UNCOMPRESSED_SIZE_EXCEEDED: "The repository expands beyond the allowed total size.",
  SOURCE_FILE_COUNT_EXCEEDED: "The repository contains more files than allowed.",
  SOURCE_FILE_TOO_LARGE: "The repository contains a file larger than allowed.",
  PROJECT_ARCHIVED: "The project was archived before the import finished.",
};

/** What a stored failure code means; unknown codes get a generic sentence, never raw text. */
export const providerFailureMessage = (code: string | null): string => (code !== null && code in FAILURES ? FAILURES[code] : FAILURES.PROVIDER_IMPORT_FAILED);
