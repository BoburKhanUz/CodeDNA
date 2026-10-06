import Link from "next/link";

import { Button } from "@/components/ui/button";

export default function NotFound() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
      <h1 className="text-xl font-semibold">Page not found</h1>
      <p className="text-muted-foreground text-sm">The page you are looking for does not exist.</p>
      <Button asChild variant="outline">
        <Link href="/">Go to the home page</Link>
      </Button>
    </main>
  );
}
