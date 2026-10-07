"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { isApiError } from "@/lib/api/errors";
import { completeGitHubAuthorization } from "@/lib/github/client";
import { isProjectId } from "@/lib/projects/client";

/**
 * Completes a GitHub authorization: sends GitHub's code and the state to
 * the API exactly once, then returns to the project (or the project list).
 * The code and state are not logged, stored or shown.
 */
export function GitHubCallback({ code, state, error }: { code?: string; state?: string; error?: string }) {
  const router = useRouter();
  const sent = useRef(false);
  const [failure, setFailure] = useState<unknown>(null);
  const declined = error !== undefined;
  const incomplete = !declined && (code === undefined || state === undefined);

  useEffect(() => {
    if (declined || incomplete || sent.current || code === undefined || state === undefined) return;
    sent.current = true;
    completeGitHubAuthorization(state, code)
      .then(({ project_id }) => {
        router.replace(project_id !== null && isProjectId(project_id) ? `/app/projects/${project_id}/github?github=connected` : "/app/projects");
      })
      .catch((e: unknown) => {
        if (isApiError(e) && e.status === 401) {
          router.replace("/login");
          return;
        }
        setFailure(e);
      });
  }, [code, state, declined, incomplete, router]);

  if (declined || incomplete || failure !== null) {
    return (
      <div className="grid max-w-md gap-3" data-testid="github-callback-error">
        <h1 className="text-2xl font-semibold tracking-tight">GitHub was not connected</h1>
        {failure !== null ? (
          <ApiErrorAlert error={failure} />
        ) : (
          <p className="text-muted-foreground">
            {declined ? "The authorization was cancelled on GitHub." : "The response from GitHub was incomplete."} Nothing was changed.
          </p>
        )}
        <Link href="/app/projects" className="underline underline-offset-4">
          Back to projects
        </Link>
      </div>
    );
  }

  return (
    <div className="grid max-w-md gap-3" role="status" aria-label="Connecting GitHub">
      <h1 className="text-2xl font-semibold tracking-tight">Connecting GitHub…</h1>
      <p className="text-muted-foreground">Verifying the authorization with GitHub.</p>
    </div>
  );
}
