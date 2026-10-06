import type { Metadata } from "next";

import { getSession } from "@/lib/auth/session";

export const metadata: Metadata = { title: "Home" };

export default async function AppHomePage() {
  // Same request as the layout's call (React cache): no second /me request.
  const session = await getSession();
  const name = session.status === "authenticated" ? session.user.name : "";

  return (
    <section className="grid max-w-2xl gap-2">
      <h1 className="text-2xl font-semibold tracking-tight">Welcome, {name}</h1>
      <p className="text-muted-foreground">
        Your CodeDNA workspace is ready. Projects, code analysis and your developer DNA will appear here as they
        become available.
      </p>
    </section>
  );
}
