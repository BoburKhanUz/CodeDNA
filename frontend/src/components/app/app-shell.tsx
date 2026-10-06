"use client";

import { Home, UserRound } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";

import { useCurrentUser } from "@/components/auth/auth-provider";
import { LogoutButton } from "@/components/auth/logout-button";
import { Logo } from "@/components/brand/logo";

const NAVIGATION = [
  { href: "/app", label: "Home", icon: Home },
  { href: "/app/profile", label: "Profile", icon: UserRound },
] as const;

/** Minimal authenticated shell: navigation, signed-in user, sign out. */
export function AppShell({ children }: { children: ReactNode }) {
  const user = useCurrentUser();
  const pathname = usePathname();

  return (
    <div className="flex min-h-full flex-1 flex-col md:flex-row">
      <aside className="bg-sidebar text-sidebar-foreground border-sidebar-border flex shrink-0 flex-col gap-6 border-b p-4 md:w-60 md:border-r md:border-b-0">
        <Link href="/app" className="w-fit rounded-md">
          <Logo />
        </Link>
        <nav aria-label="Main">
          <ul className="grid gap-1 text-sm">
            {NAVIGATION.map(({ href, label, icon: Icon }) => {
              const current = pathname === href;
              return (
                <li key={href}>
                  <Link
                    href={href}
                    aria-current={current ? "page" : undefined}
                    className={
                      current
                        ? "bg-sidebar-accent text-sidebar-accent-foreground flex items-center gap-2 rounded-md px-2 py-1.5 font-medium"
                        : "hover:bg-sidebar-accent/60 flex items-center gap-2 rounded-md px-2 py-1.5"
                    }
                  >
                    <Icon className="size-4" aria-hidden="true" />
                    {label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      </aside>
      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-end gap-4 border-b px-6 py-3">
          <div className="text-right text-sm leading-tight">
            <p className="font-medium">{user.name}</p>
            <p className="text-muted-foreground">{user.email}</p>
          </div>
          <LogoutButton />
        </header>
        <main id="main" className="flex-1 p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
