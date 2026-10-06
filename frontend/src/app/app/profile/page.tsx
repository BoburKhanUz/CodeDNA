import type { Metadata } from "next";

import { PasswordForm } from "@/components/profile/password-form";
import { ProfileSettings } from "@/components/profile/profile-settings";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { getSession } from "@/lib/auth/session";

export const metadata: Metadata = { title: "Profile" };

/** /app/profile: account identity, developer profile and password. */
export default async function ProfilePage() {
  // Same request as the layout's call (React cache): no second /me request.
  const session = await getSession();
  if (session.status !== "authenticated") return null;
  const { user } = session;

  return (
    <div className="grid max-w-3xl gap-6">
      <div className="grid gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">Profile</h1>
        <p className="text-muted-foreground">Your account, developer profile and security settings.</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>
            <h2>Account</h2>
          </CardTitle>
          <CardDescription>Your sign-in identity. Name and email cannot be changed yet.</CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-3 text-sm sm:grid-cols-[8rem_1fr]">
            <dt className="text-muted-foreground">Name</dt>
            <dd>{user.name}</dd>
            <dt className="text-muted-foreground">Email</dt>
            <dd>
              {user.email}
              {user.email_verified_at === null ? (
                <span className="text-muted-foreground"> (not verified)</span>
              ) : null}
            </dd>
          </dl>
        </CardContent>
      </Card>

      <ProfileSettings />

      <PasswordForm />
    </div>
  );
}
