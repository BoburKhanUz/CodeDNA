"use client";

import { useEffect, useState } from "react";

import { getGitHubAccount } from "@/lib/github/client";
import { listRepositoryProviderAccounts } from "@/lib/repository-providers/client";

/** Which repository providers this server has configured (V1.0.0: none). */
export interface ConfiguredProviders {
  github: boolean;
  /** GitLab or Bitbucket Cloud. */
  other: boolean;
}

/**
 * Repository import is offered only for providers the server reports as
 * configured. Any error or unexpected answer counts as "not configured", so
 * an unavailable integration is never advertised (fail closed). Nothing is
 * requested while `enabled` is false (e.g. for a malformed project ID).
 */
export function useConfiguredProviders(enabled = true): ConfiguredProviders {
  const [providers, setProviders] = useState<ConfiguredProviders>({ github: false, other: false });

  useEffect(() => {
    if (!enabled) return;
    let active = true;
    getGitHubAccount()
      .then((status) => {
        if (active) setProviders((current) => ({ ...current, github: status?.configured === true }));
      })
      .catch(() => undefined);
    listRepositoryProviderAccounts()
      .then((accounts) => {
        if (active) setProviders((current) => ({ ...current, other: Array.isArray(accounts) && accounts.some((account) => account.configured === true) }));
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [enabled]);

  return providers;
}
