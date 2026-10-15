"use client";

import { useEffect, useState } from "react";

import { getChallengeStatus } from "@/lib/challenge/client";

/**
 * Whether this server offers coding challenges (V1.0.0: no). Challenge links
 * are shown only when the server reports them enabled. Any error or
 * unexpected answer counts as "disabled", so an unavailable feature is never
 * advertised (fail closed). `null` until the server has answered: show
 * challenge links only for `true`, a "not enabled" notice only for `false`.
 * Nothing is requested while `enabled` is false (e.g. for a malformed
 * project ID).
 */
export function useChallengesEnabled(enabled = true): boolean | null {
  const [challenges, setChallenges] = useState<boolean | null>(null);

  useEffect(() => {
    if (!enabled) return;
    let active = true;
    getChallengeStatus()
      .then((status) => {
        if (active) setChallenges(status?.enabled === true);
      })
      .catch(() => {
        if (active) setChallenges(false);
      });
    return () => {
      active = false;
    };
  }, [enabled]);

  return challenges;
}
