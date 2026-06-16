import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath, pathToFileURL } from "node:url";
import { query } from "@anthropic-ai/claude-agent-sdk";
import { REVIEW_JSON_SCHEMA, REVIEW_SCHEMA, SYSTEM_PROMPT, type Review } from "./review-schema";

const MODEL = process.env.AI_REVIEW_MODEL ?? "claude-sonnet-4-6";
const MAX_BUDGET_USD = Number(process.env.AI_REVIEW_MAX_BUDGET_USD ?? "0.5");

/** Read the diff from --diff-file / $DIFF_FILE, else from stdin (local `git diff | npm run review`). */
export async function readDiff(): Promise<string> {
  const fileArg = process.argv.find((a) => a.startsWith("--diff-file="))?.split("=")[1];
  const diffFile = fileArg ?? process.env.DIFF_FILE;
  if (diffFile) return readFileSync(diffFile, "utf8");

  const chunks: Buffer[] = [];
  for await (const chunk of process.stdin) chunks.push(chunk as Buffer);
  return Buffer.concat(chunks).toString("utf8");
}

/** Resolve the DoD criteria file: $CRITERIA_FILE, else $GITHUB_WORKSPACE, else repo-relative default. */
export function readCriteria(): string {
  const explicit = process.env.CRITERIA_FILE;
  if (explicit) return readFileSync(explicit, "utf8");

  if (process.env.GITHUB_WORKSPACE) {
    return readFileSync(`${process.env.GITHUB_WORKSPACE}/.github/review/criteria.md`, "utf8");
  }
  // src/review.ts -> ../../../ is the repo root (code-reviewer -> packages -> repo).
  const fallback = fileURLToPath(new URL("../../../.github/review/criteria.md", import.meta.url));
  return readFileSync(fallback, "utf8");
}

export function buildPrompt(criteria: string, diff: string): string {
  const title = process.env.PR_TITLE?.trim();
  const body = process.env.PR_BODY?.trim();
  const context =
    title || body
      ? `## Kontekst PR-a\nTytuł: ${title ?? "(brak)"}\nOpis: ${body ?? "(brak)"}\n\n`
      : "";
  return (
    `${context}## Kryteria Definition of Done\n${criteria}\n\n` +
    `## Diff do recenzji (unified, względem main)\n\`\`\`diff\n${diff}\n\`\`\``
  );
}

export async function review(criteria: string, diff: string): Promise<Review> {
  const result = query({
    prompt: buildPrompt(criteria, diff),
    options: {
      systemPrompt: SYSTEM_PROMPT,
      model: MODEL,
      tools: [],
      maxTurns: 2,
      maxBudgetUsd: MAX_BUDGET_USD,
      outputFormat: { type: "json_schema", schema: REVIEW_JSON_SCHEMA },
    },
  });

  for await (const message of result) {
    if (message.type !== "result") continue;

    if (message.subtype === "success") {
      // Telemetry to stderr so stdout stays clean JSON.
      console.error(
        `[reviewer] model=${MODEL} turns=${message.num_turns} cost_usd=${message.total_cost_usd}`,
      );
      const parsed = REVIEW_SCHEMA.safeParse(message.structured_output);
      if (!parsed.success) {
        throw new Error(`Niepoprawny structured output: ${parsed.error.message}`);
      }
      return parsed.data;
    }

    // error_max_turns | error_during_execution | error_max_budget_usd | error_max_structured_output_retries
    throw new Error(`Review nie powiodło się (${message.subtype}): ${message.errors.join("; ")}`);
  }

  throw new Error("Agent nie zwrócił wyniku");
}

/** CLI entry: read criteria + diff, run the agent, emit JSON to stdout (+ optional REPORT_FILE). */
export async function runCli(): Promise<void> {
  const criteria = readCriteria();
  const diff = await readDiff();

  if (diff.trim().length === 0) {
    console.error("[reviewer] Pusty diff — nic do recenzji.");
    process.exitCode = 1;
    return;
  }

  try {
    const data = await review(criteria, diff);
    const json = JSON.stringify(data, null, 2);
    if (process.env.REPORT_FILE) writeFileSync(process.env.REPORT_FILE, json);
    console.log(json);
  } catch (err: unknown) {
    console.error(`[reviewer] ${err instanceof Error ? err.message : String(err)}`);
    process.exitCode = 1;
  }
}

// Run the CLI only when this file is the process entry point — not when imported by tests.
const invokedDirectly =
  process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href;
if (invokedDirectly) {
  void runCli();
}
