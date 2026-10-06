import type { Metadata } from "next";

import { LoginForm } from "@/components/auth/login-form";

export const metadata: Metadata = { title: "Sign in" };

/**
 * `?reason=password-changed` (set after a password change) shows a fixed
 * notice. The parameter is only compared, never rendered or used as a
 * redirect target.
 */
export default async function LoginPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const { reason } = await searchParams;
  return <LoginForm notice={reason === "password-changed" ? "password-changed" : undefined} />;
}
