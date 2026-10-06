import { redirect } from "next/navigation";
import type { ReactNode } from "react";

import { AppShell } from "@/components/app/app-shell";
import { AuthProvider } from "@/components/auth/auth-provider";
import { getSession } from "@/lib/auth/session";

/**
 * The authenticated area. The server asks Laravel for the session before
 * rendering anything, so signed-out visitors never receive app markup. This
 * is navigation/UX protection: every API call is still authorized by
 * Laravel itself.
 */
export default async function AuthenticatedLayout({ children }: { children: ReactNode }) {
  const session = await getSession();
  if (session.status !== "authenticated") {
    redirect("/login");
  }

  return (
    <AuthProvider user={session.user}>
      <AppShell>{children}</AppShell>
    </AuthProvider>
  );
}
