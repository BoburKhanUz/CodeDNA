import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { UploadSource } from "@/components/projects/upload-source";
import { MAX_ARCHIVE_BYTES } from "@/lib/api/types";
import { project, snapshot } from "@/test/responses";
import { resetRouter, router } from "@/test/router";
import { FakeXhr } from "@/test/xhr";

vi.mock("next/navigation", async () => {
  const { router } = await import("@/test/router");
  return { useRouter: () => router };
});

beforeEach(() => {
  resetRouter();
  FakeXhr.reset();
  vi.stubGlobal("XMLHttpRequest", FakeXhr);
  document.cookie = "XSRF-TOKEN=token; path=/";
});

afterEach(() => FakeXhr.reset());

const zip = (name = "source.zip", size = 1024) => {
  const file = new File(["PK"], name, { type: "application/zip" });
  Object.defineProperty(file, "size", { value: size });
  return file;
};

async function choose(file: File) {
  await userEvent.setup().upload(screen.getByLabelText("ZIP archive"), file);
}

describe("UploadSource", () => {
  it("checks the file in the browser before uploading", async () => {
    const ui = userEvent.setup({ applyAccept: false });
    render(<UploadSource projectId={project.id} onUploaded={vi.fn()} />);

    await ui.click(screen.getByRole("button", { name: "Upload source" }));
    expect(screen.getByText("Choose a ZIP archive.")).toBeInTheDocument();

    await ui.upload(screen.getByLabelText("ZIP archive"), zip("source.tar.gz"));
    expect(screen.getByText("Choose a .zip file.")).toBeInTheDocument();

    await ui.upload(screen.getByLabelText("ZIP archive"), zip("big.zip", MAX_ARCHIVE_BYTES + 1));
    expect(screen.getByText("The file is larger than 50 MB.")).toBeInTheDocument();
    await ui.click(screen.getByRole("button", { name: "Upload source" }));

    expect(FakeXhr.instances).toHaveLength(0);
  });

  it("shows progress, then the created snapshot version (not analysis)", async () => {
    const onUploaded = vi.fn();
    let finish: () => void = () => {};
    FakeXhr.handler = (xhr) => {
      xhr.progress(40, 100);
      finish = () => xhr.respond(201, { data: snapshot(1) });
    };
    render(<UploadSource projectId={project.id} onUploaded={onUploaded} />);

    await choose(zip());
    await userEvent.setup().click(screen.getByRole("button", { name: "Upload source" }));

    expect(await screen.findByRole("progressbar")).toHaveAttribute("aria-valuenow", "40");
    expect(screen.getByText("Uploading… 40%")).toBeInTheDocument();
    finish();

    expect(await screen.findByText("Source snapshot v1 created.")).toBeInTheDocument();
    expect(screen.queryByText(/analy/i)).not.toBeInTheDocument();
    expect(onUploaded).toHaveBeenCalledWith(snapshot(1));
    expect(FakeXhr.instances[0]?.url).toBe(`/api/v1/projects/${project.id}/source-snapshots`);
  });

  it("explains rejected archives without echoing server text", async () => {
    FakeXhr.handler = (xhr) =>
      xhr.respond(422, { error: { code: "SOURCE_ARCHIVE_UNSAFE", message: "server text <b>", request_id: "rid" } });
    render(<UploadSource projectId={project.id} onUploaded={vi.fn()} />);

    await choose(zip());
    await userEvent.setup().click(screen.getByRole("button", { name: "Upload source" }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("The archive contains an unsafe entry");
    expect(alert).not.toHaveTextContent("server text");
  });

  it("reuses the idempotency key when retrying the same file after a network failure", async () => {
    let attempt = 0;
    FakeXhr.handler = (xhr) => {
      attempt++;
      if (attempt === 1) xhr.failNetwork();
      else xhr.respond(200, { data: snapshot(1) }, { "Idempotent-Replayed": "true" });
    };
    render(<UploadSource projectId={project.id} onUploaded={vi.fn()} />);
    const ui = userEvent.setup();

    await choose(zip());
    await ui.click(screen.getByRole("button", { name: "Upload source" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("Unable to connect to CodeDNA");
    await ui.click(screen.getByRole("button", { name: "Upload source" }));
    expect(await screen.findByText("Source snapshot v1 created.")).toBeInTheDocument();

    const [first, second] = FakeXhr.instances;
    expect(first?.requestHeaders["Idempotency-Key"]).toMatch(/^[0-9a-f-]{36}$/);
    expect(second?.requestHeaders["Idempotency-Key"]).toBe(first?.requestHeaders["Idempotency-Key"]);
  });

  it("uses a new idempotency key for a newly chosen file", async () => {
    FakeXhr.handler = (xhr) => xhr.respond(201, { data: snapshot(FakeXhr.instances.length) });
    render(<UploadSource projectId={project.id} onUploaded={vi.fn()} />);
    const ui = userEvent.setup();

    await choose(zip("a.zip"));
    await ui.click(screen.getByRole("button", { name: "Upload source" }));
    await screen.findByText("Source snapshot v1 created.");
    await choose(zip("b.zip"));
    await ui.click(screen.getByRole("button", { name: "Upload source" }));
    await screen.findByText("Source snapshot v2 created.");

    const [first, second] = FakeXhr.instances;
    expect(second?.requestHeaders["Idempotency-Key"]).not.toBe(first?.requestHeaders["Idempotency-Key"]);
  });

  it("sends the user to sign in when the session has ended", async () => {
    FakeXhr.handler = (xhr) => xhr.respond(401, { error: { code: "AUTHENTICATION_REQUIRED", message: "x", request_id: null } });
    render(<UploadSource projectId={project.id} onUploaded={vi.fn()} />);

    await choose(zip());
    await userEvent.setup().click(screen.getByRole("button", { name: "Upload source" }));

    await vi.waitFor(() => expect(router.replace).toHaveBeenCalledWith("/login"));
  });
});
