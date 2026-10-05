// Phase 02 bootstrap page: proves the Next.js container is served through Nginx.
// The product UI is built from Phase 04 onward.
export default function Home() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-2 p-8">
      <h1 className="text-2xl font-semibold tracking-tight">CodeDNA</h1>
      <p className="text-sm text-neutral-500">
        Frontend container is running. The product interface is not built yet.
      </p>
    </main>
  );
}
