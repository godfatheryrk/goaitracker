import { z } from "zod";

/**
 * System prompt — the reviewer's role. The actual Definition-of-Done criteria are NOT hardcoded
 * here; they are injected at call time from `.github/review/criteria.md` (single source of truth,
 * shared with the promptfoo eval suite). Keep this prompt narrow and deterministic.
 */
export const SYSTEM_PROMPT = `Jesteś precyzyjnym, konstruktywnym recenzentem kodu oceniającym pull request w projekcie Laravel 12 / PHP 8.4.
Otrzymasz: (1) kryteria Definition of Done oraz (2) unified git diff względem gałęzi main.
Oceń diff WYŁĄCZNIE według dostarczonych kryteriów. Dla każdego kryterium nadaj ocenę całkowitą 1-10
(1 = poważne braki, 10 = wzorowo), werdykt pass/fail oraz konkretny dowód (plik/linia z diffa).
Następnie wydaj jeden wiążący overall_verdict wg reguły werdyktu z kryteriów.
Zwróć WYŁĄCZNIE obiekt JSON zgodny z wymuszonym schematem — bez prozy, bez bloków markdown.`;

/**
 * One criterion entry. Scores are plain numbers (1-10): Anthropic structured output rejects
 * minimum/maximum on integer types, so the range lives in the description + prompt, not the schema.
 */
const CriterionSchema = z.object({
  name: z
    .string()
    .describe("Nazwa kryterium DoD. Wpis dotyczący izolacji MUSI zawierać słowo 'isolation'."),
  score: z
    .number()
    .describe("Ocena całkowita w skali 1-10 (1 = najgorzej, 10 = wzorowo)."),
  verdict: z.enum(["pass", "fail"]).describe("Werdykt pass/fail dla tego kryterium."),
  evidence: z
    .string()
    .describe("Konkretny dowód z diffa uzasadniający ocenę (plik/linia lub fragment)."),
});

/**
 * Full review result. Shape MUST match the "Output contract" block in
 * `.github/review/criteria.md` and the promptfoo assertions.
 */
export const REVIEW_SCHEMA = z.object({
  criteria: z
    .array(CriterionSchema)
    .describe("Po jednym wpisie na każde kryterium DoD z dostarczonych kryteriów."),
  overall_verdict: z
    .enum(["pass", "fail"])
    .describe(
      "fail, jeśli kryterium izolacji = fail LUB dowolne kryterium < 6; w przeciwnym razie pass.",
    ),
  summary: z
    .string()
    .describe("Podsumowanie 2-3 zdania w Markdown, gotowe jako komentarz dla autora PR-a."),
});

/** JSON Schema (draft-07) handed to the Claude Agent SDK `outputFormat`. */
export const REVIEW_JSON_SCHEMA = z.toJSONSchema(REVIEW_SCHEMA, { target: "draft-7" });

export type Review = z.infer<typeof REVIEW_SCHEMA>;
