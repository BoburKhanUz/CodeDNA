import type { ReactNode } from "react";

import { Label } from "@/components/ui/label";

/** Accessibility attributes linking a control to its hint and error message. */
export function describedBy(id: string, error?: string, hint?: string) {
  const ids = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean);
  return {
    id,
    "aria-invalid": error ? true : undefined,
    "aria-describedby": ids.length > 0 ? ids.join(" ") : undefined,
  } as const;
}

/** Label, control, optional hint and field-level error (used with describedBy()). */
export function Field({
  id,
  label,
  hint,
  error,
  className,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={className ? `grid gap-2 ${className}` : "grid gap-2"}>
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint ? (
        <p id={`${id}-hint`} className="text-muted-foreground text-xs">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={`${id}-error`} className="text-destructive text-sm">
          {error}
        </p>
      ) : null}
    </div>
  );
}
