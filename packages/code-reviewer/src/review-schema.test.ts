import { describe, expect, it } from "vitest";
import { REVIEW_JSON_SCHEMA, REVIEW_SCHEMA } from "./review-schema";

const validReview = {
  criteria: [
    { name: "Per-user isolation", score: 9, verdict: "pass", evidence: "show() uses ventures()" },
    { name: "Security / secrets", score: 8, verdict: "pass", evidence: "secrets via env" },
  ],
  overall_verdict: "pass",
  summary: "Looks good.",
};

describe("REVIEW_SCHEMA", () => {
  it("accepts a well-formed review", () => {
    const parsed = REVIEW_SCHEMA.safeParse(validReview);
    expect(parsed.success).toBe(true);
  });

  it("rejects a missing overall_verdict", () => {
    const { overall_verdict, ...withoutVerdict } = validReview;
    void overall_verdict;
    expect(REVIEW_SCHEMA.safeParse(withoutVerdict).success).toBe(false);
  });

  it("rejects an out-of-enum verdict", () => {
    const bad = { ...validReview, overall_verdict: "maybe" };
    expect(REVIEW_SCHEMA.safeParse(bad).success).toBe(false);
  });

  it("rejects a criterion missing required fields", () => {
    const bad = { ...validReview, criteria: [{ name: "x" }] };
    expect(REVIEW_SCHEMA.safeParse(bad).success).toBe(false);
  });
});

describe("REVIEW_JSON_SCHEMA", () => {
  // zod types toJSONSchema() loosely; assert against a structural view of the generated schema.
  const schema = REVIEW_JSON_SCHEMA as unknown as {
    type: string;
    properties: Record<string, { enum?: string[]; items?: { properties: Record<string, unknown> } }>;
  };

  it("is an object schema matching the output contract", () => {
    expect(schema.type).toBe("object");
    expect(Object.keys(schema.properties)).toEqual(["criteria", "overall_verdict", "summary"]);
    expect(schema.properties.overall_verdict.enum).toEqual(["pass", "fail"]);
    expect(Object.keys(schema.properties.criteria.items!.properties)).toEqual([
      "name",
      "score",
      "verdict",
      "evidence",
    ]);
  });
});
