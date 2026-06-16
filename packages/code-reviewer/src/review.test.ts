import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { query } from "@anthropic-ai/claude-agent-sdk";
import { buildPrompt, readDiff, review } from "./review";

vi.mock("@anthropic-ai/claude-agent-sdk", () => ({ query: vi.fn() }));

const mockedQuery = vi.mocked(query);

/** Build an async-iterable of SDK messages, matching what `query()` returns. */
function stream(messages: unknown[]): AsyncIterable<unknown> {
  return (async function* () {
    for (const m of messages) yield m;
  })();
}

const validReview = {
  criteria: [
    { name: "Per-user isolation", score: 9, verdict: "pass", evidence: "ventures()" },
    { name: "Test coverage", score: 7, verdict: "pass", evidence: "feature test present" },
  ],
  overall_verdict: "pass",
  summary: "ok",
};

function successMessage(structured: unknown) {
  return { type: "result", subtype: "success", structured_output: structured, num_turns: 2, total_cost_usd: 0.01 };
}

const ORIGINAL_ENV = { ...process.env };

beforeEach(() => {
  vi.spyOn(console, "error").mockImplementation(() => {});
  mockedQuery.mockReset();
});

afterEach(() => {
  vi.restoreAllMocks();
  process.env = { ...ORIGINAL_ENV };
});

describe("review()", () => {
  it("returns the parsed verdict on a successful, schema-valid response", async () => {
    mockedQuery.mockReturnValue(stream([successMessage(validReview)]) as never);

    const result = await review("CRITERIA", "DIFF");

    expect(result.overall_verdict).toBe("pass");
    expect(result.criteria).toHaveLength(2);
    expect(mockedQuery).toHaveBeenCalledOnce();
  });

  it("throws on an SDK error subtype (e.g. budget exceeded), surfacing the errors", async () => {
    mockedQuery.mockReturnValue(
      stream([{ type: "result", subtype: "error_max_budget_usd", errors: ["budget exceeded"] }]) as never,
    );

    await expect(review("C", "D")).rejects.toThrow(/error_max_budget_usd.*budget exceeded/);
  });

  it("throws when the structured output does not match the schema", async () => {
    mockedQuery.mockReturnValue(stream([successMessage({ unexpected: true })]) as never);

    await expect(review("C", "D")).rejects.toThrow(/Niepoprawny structured output/);
  });

  it("throws when the stream yields no result message", async () => {
    mockedQuery.mockReturnValue(stream([{ type: "assistant" }]) as never);

    await expect(review("C", "D")).rejects.toThrow(/Agent nie zwrócił wyniku/);
  });
});

describe("buildPrompt()", () => {
  it("embeds the criteria and the diff", () => {
    const prompt = buildPrompt("MY_CRITERIA", "MY_DIFF");
    expect(prompt).toContain("MY_CRITERIA");
    expect(prompt).toContain("MY_DIFF");
  });

  it("folds in PR title/body when provided", () => {
    process.env.PR_TITLE = "Fix isolation";
    process.env.PR_BODY = "Closes #1";
    const prompt = buildPrompt("C", "D");
    expect(prompt).toContain("Kontekst PR-a");
    expect(prompt).toContain("Fix isolation");
    expect(prompt).toContain("Closes #1");
  });

  it("omits the PR context block when no title/body is set", () => {
    delete process.env.PR_TITLE;
    delete process.env.PR_BODY;
    expect(buildPrompt("C", "D")).not.toContain("Kontekst PR-a");
  });
});

describe("readDiff()", () => {
  it("reads the diff from $DIFF_FILE when set", async () => {
    const dir = mkdtempSync(join(tmpdir(), "reviewer-"));
    const file = join(dir, "pr.diff");
    writeFileSync(file, "DIFF_FROM_FILE");
    process.env.DIFF_FILE = file;

    await expect(readDiff()).resolves.toBe("DIFF_FROM_FILE");
  });
});
