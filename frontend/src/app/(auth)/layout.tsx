import Link from "next/link";
import { redirect } from "next/navigation";
import type { ReactNode } from "react";

import { Logo } from "@/components/brand/logo";
import { getSession } from "@/lib/auth/session";

/** /login and /register: signed-in visitors are sent to the app. */
export default async function AuthLayout({ children }: { children: ReactNode }) {
  const session = await getSession();
  if (session.status === "authenticated") {
    redirect("/app");
  }

  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-6 p-6">
      <Link href="/" className="rounded-md">
        <Logo className="text-lg" />
      </Link>
      {children}
    </main>
  );
}
