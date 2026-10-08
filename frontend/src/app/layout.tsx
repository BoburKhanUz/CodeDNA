import type { Metadata } from "next";
import { connection } from "next/server";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "CodeDNA", template: "%s · CodeDNA" },
  description: "Developer intelligence platform",
};

export default async function RootLayout({ children }: LayoutProps<"/">) {
  // Every page renders per request so Next.js can apply the response's CSP
  // nonce to its scripts (src/proxy.ts); a prerendered page has no nonce.
  await connection();
  return (
    <html lang="en" className="h-full antialiased">
      <body className="flex min-h-full flex-col">{children}</body>
    </html>
  );
}
