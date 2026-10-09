"use client";

import { useEffect, useState } from "react";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { Installation, LicenseStatus } from "@/lib/api/types";
import { getInstallation } from "@/lib/billing/client";
import { formatDateTime } from "@/lib/projects/format";

const PROBLEMS: Partial<Record<LicenseStatus, string>> = {
  EXPIRED: "has expired",
  NOT_YET_VALID: "is not valid yet",
  WRONG_INSTALLATION: "was issued for another installation",
  INVALID_SIGNATURE: "could not be verified",
  UNKNOWN_KEY: "could not be verified",
  MALFORMED: "could not be read",
  UNSUPPORTED_VERSION: "is not supported by this version",
  UNREADABLE: "could not be read",
};

/**
 * The installation's edition and license status (Phase 27,
 * docs/enterprise/enterprise-architecture.md#status). Informational only:
 * the server decides every entitlement where it is used. Renders nothing
 * until (or unless) the status is available.
 */
export function InstallationCard() {
  const [installation, setInstallation] = useState<Installation | null>(null);

  useEffect(() => {
    let active = true;
    getInstallation()
      .then((data) => {
        if (active && data?.type === "installation") setInstallation(data);
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  if (installation === null) return null;
  const { edition, license } = installation;
  const problem = PROBLEMS[license.status];

  return (
    <Card data-testid="installation" data-edition={edition}>
      <CardHeader>
        <CardTitle>
          <h2>{edition === "ENTERPRISE" ? "Enterprise edition" : "Community edition"}</h2>
        </CardTitle>
        <CardDescription>This installation of CodeDNA.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-2 text-sm">
        {edition === "ENTERPRISE" ? (
          <p data-testid="installation-license">
            Licensed to {license.licensee} until {formatDateTime(license.expires_at ?? "")}. Teams use the Team plan with up to{" "}
            {license.organization_seats} seats. Your personal plan is unchanged.
          </p>
        ) : problem !== undefined ? (
          <p role="note" data-testid="installation-license-problem">
            An enterprise license is installed but {problem}, so it is not in effect. Teams keep their own plan and seats until an
            administrator installs a valid license.
          </p>
        ) : (
          <p data-testid="installation-community">Teams use their own plan and seat limit.</p>
        )}
      </CardContent>
    </Card>
  );
}
