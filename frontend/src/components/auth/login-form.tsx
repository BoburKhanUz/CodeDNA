"use client";

import { Info } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import { firstServerErrors, hasErrors, validateEmail, type FieldErrors } from "@/components/auth/validation";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { isApiError } from "@/lib/api/errors";
import { login } from "@/lib/auth/client";
import { afterSignInPath } from "@/lib/organizations/invitation-link";

const FIELDS = ["email", "password"] as const;
type Field = (typeof FIELDS)[number];

export function LoginForm({ notice }: { notice?: "password-changed" } = {}) {
  const router = useRouter();
  const [values, setValues] = useState<Record<Field, string>>({ email: "", password: "" });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<Field>>({});
  const [formError, setFormError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);

    const errors: FieldErrors<Field> = {
      email: validateEmail(values.email),
      password: values.password === "" ? "Enter your password." : undefined,
    };
    setFieldErrors(errors);
    if (hasErrors(errors)) return;

    setSubmitting(true);
    try {
      await login({ email: values.email.trim(), password: values.password });
      router.replace(afterSignInPath());
      router.refresh();
    } catch (error) {
      if (isApiError(error) && error.code === "VALIDATION_FAILED") {
        setFieldErrors(firstServerErrors(error.fieldErrors, FIELDS));
      }
      setFormError(error);
      setSubmitting(false);
    }
  }

  function update(field: Field) {
    return (event: { target: { value: string } }) => setValues((current) => ({ ...current, [field]: event.target.value }));
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>
          <h1>Sign in</h1>
        </CardTitle>
        <CardDescription>Welcome back to CodeDNA.</CardDescription>
      </CardHeader>
      <form onSubmit={onSubmit} noValidate aria-busy={submitting}>
        <CardContent className="grid gap-4">
          {formError ? <ApiErrorAlert error={formError} /> : null}
          {notice === "password-changed" && !formError ? (
            <Alert role="status">
              <Info aria-hidden="true" />
              <AlertDescription>Your password was changed. Sign in with your new password.</AlertDescription>
            </Alert>
          ) : null}
          <FormField
            id="email"
            label="Email"
            type="email"
            autoComplete="email"
            value={values.email}
            onChange={update("email")}
            error={fieldErrors.email}
            disabled={submitting}
          />
          <FormField
            id="password"
            label="Password"
            type="password"
            autoComplete="current-password"
            value={values.password}
            onChange={update("password")}
            error={fieldErrors.password}
            disabled={submitting}
          />
        </CardContent>
        <CardFooter className="mt-6 flex flex-col gap-4">
          <Button type="submit" className="w-full" disabled={submitting}>
            {submitting ? "Signing in…" : "Sign in"}
          </Button>
          <p className="text-muted-foreground text-sm">
            New to CodeDNA?{" "}
            <Link href="/register" className="text-foreground underline underline-offset-4">
              Create an account
            </Link>
          </p>
        </CardFooter>
      </form>
    </Card>
  );
}
