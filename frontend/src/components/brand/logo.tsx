import { Dna } from "lucide-react";

import { cn } from "@/lib/utils";

export function Logo({ className }: { className?: string }) {
  return (
    <span className={cn("inline-flex items-center gap-2 font-semibold tracking-tight", className)}>
      <span className="bg-primary text-primary-foreground inline-flex size-7 items-center justify-center rounded-md">
        <Dna className="size-4" aria-hidden="true" />
      </span>
      CodeDNA
    </span>
  );
}
