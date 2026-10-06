import { AlertCircle } from "lucide-react";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { describeApiError, isApiError, shouldShowReference } from "@/lib/api/errors";

/** Displays a failed request using safe, client-owned wording only. */
export function ApiErrorAlert({ error }: { error: unknown }) {
  return (
    <Alert variant="destructive" role="alert">
      <AlertCircle aria-hidden="true" />
      <AlertDescription>
        <p>{describeApiError(error)}</p>
        {isApiError(error) && shouldShowReference(error) ? (
          <p className="text-xs opacity-80">
            Reference: <code className="font-mono">{error.requestId}</code>
          </p>
        ) : null}
      </AlertDescription>
    </Alert>
  );
}
