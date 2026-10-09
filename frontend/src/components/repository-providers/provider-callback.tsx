"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { isApiError } from "@/lib/api/errors";
import type { RepositoryProviderKey } from "@/lib/api/types";
import { isProjectId } from "@/lib/projects/client";
import { completeProviderAuthorization } from "@/lib/repository-providers/client";

const NAMES: Record<RepositoryProviderKey, string> = { gitlab: "GitLab", bitbucket: "Bitbucket Cloud" };

/**
 * Completes a GitLab or Bitbucket Cloud authorization: sends the provider's
 * code and the state to the API exactly once, then returns to the project (or
 * the project list). The code and state are not logged, stored or shown.
 */
export function ProviderCallback({ provider, code, state, error }: { provider: RepositoryProviderKey; code?: string; state?: string; error?: string }) {
  const router = useRouter();
  const sent = useRef(false);
  const [failure, setFailure] = useState<unknown>(null);
  const name = NAMES[provider];
  const declined = error !== undefined;
  const incomplete = !declined && (code === undefined || state === undefined);

  useEffect(() => {
    if (declined || incomplete || sent.current || code === undefined || state === undefined) return;
    sent.current = true;
    completeProviderAuthorization(provider, state, code)
      .then(({ project_id }) => {
        router.replace(project_id !== null && isProjectId(project_id) ? `/app/projects/${project_id}/repositories?provider=connected` : "/app/projects");
      })
      .catch((e: unknown) => {
        if (isApiError(e) && e.status === 401) {
          router.replace("/login");
          return;
        }
        setFailure(e);
      });
  }, [provider, code, state, declined, incomplete, router]);

  if (declined || incomplete || failure !== null) {
    return (
      <div className="grid max-w-md gap-3" data-testid="provider-callback-error">
        <h1 className="text-2xl font-semibold tracking-tight">{name} was not connected</h1>
        {failure !== null ? (
          <ApiErrorAlert error={failure} />
        ) : (
          <p className="text-muted-foreground">
            {declined ? `The authorization was cancelled on ${name}.` : `The response from ${name} was incomplete.`} Nothing was changed.
          </p>
        )}
        <Link href="/app/projects" className="underline underline-offset-4">
          Back to projects
        </Link>
      </div>
    );
  }

  return (
    <div className="grid max-w-md gap-3" role="status" aria-label={`Connecting ${name}`}>
      <h1 className="text-2xl font-semibold tracking-tight">Connecting {name}…</h1>
      <p className="text-muted-foreground">Verifying the authorization with {name}.</p>
    </div>
  );
}
