"use client";

import { LogOut } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { describeApiError } from "@/lib/api/errors";
import { logout } from "@/lib/auth/client";

export function LogoutButton() {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onLogout() {
    setPending(true);
    setError(null);
    try {
      await logout();
      router.replace("/login");
      router.refresh();
    } catch (failure) {
      setError(describeApiError(failure));
      setPending(false);
    }
  }

  return (
    <div className="grid gap-1">
      <Button variant="outline" size="sm" onClick={onLogout} disabled={pending}>
        <LogOut aria-hidden="true" />
        {pending ? "Signing out…" : "Sign out"}
      </Button>
      {error ? (
        <p role="alert" className="text-destructive text-xs">
          {error}
        </p>
      ) : null}
    </div>
  );
}
