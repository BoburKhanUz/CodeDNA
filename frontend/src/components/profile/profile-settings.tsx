"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { ProfileForm } from "@/components/profile/profile-form";
import { Button } from "@/components/ui/button";
import { isApiError } from "@/lib/api/errors";
import type { DeveloperProfile } from "@/lib/api/types";
import { getProfile } from "@/lib/profile/client";

type State = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; profile: DeveloperProfile };

/** Loads the profile with the shared API client, then shows the edit form. */
export function ProfileSettings() {
  const router = useRouter();
  const [state, setState] = useState<State>({ status: "loading" });

  const load = useCallback(() => {
    getProfile()
      .then((profile) => setState({ status: "ready", profile }))
      .catch((error: unknown) => {
        if (isApiError(error) && error.status === 401) {
          router.replace("/login");
          router.refresh();
          return;
        }
        setState({ status: "error", error });
      });
  }, [router]);

  useEffect(load, [load]);

  if (state.status === "loading") {
    return (
      <p role="status" className="text-muted-foreground text-sm">
        Loading your profile…
      </p>
    );
  }

  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <ApiErrorAlert error={state.error} />
        <Button
          variant="outline"
          className="w-fit"
          onClick={() => {
            setState({ status: "loading" });
            load();
          }}
        >
          Try again
        </Button>
      </div>
    );
  }

  return <ProfileForm profile={state.profile} />;
}
