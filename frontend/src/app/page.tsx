import Link from "next/link";

import { Logo } from "@/components/brand/logo";
import { Button } from "@/components/ui/button";

/** Public landing page. It makes no API calls; /login and /app handle session redirects. */
export default function Home() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-8 p-8 text-center">
      <Logo className="text-xl" />
      <div className="grid max-w-xl gap-3">
        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Understand how you write code.</h1>
        <p className="text-muted-foreground">
          CodeDNA analyzes real source code to build an evidence-based profile of a developer&apos;s strengths, gaps
          and growth.
        </p>
      </div>
      <div className="flex flex-wrap justify-center gap-3">
        <Button asChild>
          <Link href="/login">Sign in</Link>
        </Button>
        <Button asChild variant="outline">
          <Link href="/register">Create account</Link>
        </Button>
      </div>
    </main>
  );
}
