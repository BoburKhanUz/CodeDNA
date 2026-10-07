import { beforeEach, describe, expect, it, vi } from "vitest";

import { getGrowth, getGrowthSnapshot, getGrowthTimeline } from "@/lib/growth/client";
import { compared, GROWTH_ID, overview } from "@/test/growth";
import { jsonResponse, page, project } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

describe("growth client", () => {
  it("only ever reads, from the project's growth paths", async () => {
    fetchMock.mockImplementation(async (input) =>
      String(input).includes("timeline") ? jsonResponse(page([])) : String(input).endsWith("/growth") ? jsonResponse({ data: overview(null) }) : jsonResponse({ data: compared() }),
    );

    await expect(getGrowth(project.id)).resolves.toEqual(overview(null));
    await getGrowthTimeline(project.id, 2, 5);
    await expect(getGrowthSnapshot(project.id, GROWTH_ID.toUpperCase())).resolves.toEqual(compared());

    expect(fetchMock.mock.calls.map(([url]) => String(url))).toEqual([
      `/api/v1/projects/${project.id}/growth`,
      `/api/v1/projects/${project.id}/growth/timeline?page=2&per_page=5`,
      `/api/v1/projects/${project.id}/growth/${GROWTH_ID}`,
    ]);
    expect(fetchMock.mock.calls.map(([, init]) => init?.method)).toEqual(["GET", "GET", "GET"]);
  });

  it("refuses invalid IDs without a request", async () => {
    await expect(getGrowth("../admin")).rejects.toThrow("Invalid project ID.");
    await expect(getGrowthSnapshot(project.id, "../../timeline")).rejects.toThrow("Invalid growth snapshot ID.");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
