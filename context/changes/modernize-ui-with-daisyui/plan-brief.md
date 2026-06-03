# Modernize the UI with daisyUI — Plan Brief

> Full plan: `context/changes/modernize-ui-with-daisyui/plan.md`
> Roadmap slice: `context/foundation/roadmap.md` (S-07, decisions resolved 2026-06-03)

## What & Why

Restyle every shipped surface of GOAITracker onto **daisyUI v5** (on the existing Tailwind v4 + Vite
pipeline), with a **light/dark theme** switched purely in CSS, and **zero behavioural regressions**. The
app currently uses raw, theme-blind Tailwind utilities and still ships Laravel's default welcome scaffold;
this slice makes the UI consistent and themeable. It adds no functional requirement — it's a UX-quality pass.

## Starting Point

14 Blade views + 2 layouts + a nav partial, all styled with raw utilities (`bg-white`, `bg-gray-100`,
`text-gray-*`) that don't adapt to a theme. One vanilla-JS island in `app.js` drives the completion toggle
via `data-*` selectors. daisyUI is not installed. Several tests assert on copy, form-field names, and — for
the deadline badge — exact CSS class strings.

## Desired End State

Every surface renders through 5 reusable daisyUI components and semantic theme tokens. A sun/moon toggle in
the authenticated nav flips light↔dark instantly with no JS and no reload; guest screens honour the OS
preference. The completion toggle, deadline badges, progress text, and inline delete confirmations behave
exactly as before. The test suite is green; the build compiles with daisyUI active.

## Key Decisions Made

| Decision                  | Choice                                                              | Why (1 sentence)                                                              | Source |
| ------------------------- | ------------------------------------------------------------------ | ----------------------------------------------------------------------------- | ------ |
| Dark mode                 | Light + dark via pure-CSS `theme-controller`                       | Locked in roadmap; no JS keeps the existing island untouched.                 | Roadmap |
| Restyle strategy          | Reusable anonymous Blade components `components/ui/*`              | Locked in roadmap; DRYs the restyle across 14 views.                          | Roadmap |
| Theme palette             | Stock daisyUI `light` / `dark` (no custom block)                   | Locked in roadmap; fastest path, easy to extend later.                        | Roadmap |
| Component set             | 5 anonymous: button, card, input, alert, badge                     | Covers every repeated pattern without over-abstracting.                       | Plan |
| Dark-mode conversion depth| Full conversion of all `bg-white`/`text-gray-*` → semantic tokens  | Dark mode only actually works if cards/text use `base-*` tokens everywhere.   | Plan |
| Deadline-pressure classes | Convert to `text-error`/`text-warning` + update JS & tests lockstep| Themed colors; the triple-coupling is edited in one phase to stay safe.       | Plan |
| Welcome page `/`          | Replace scaffold with a small branded daisyUI landing              | Removes the off-brand Laravel default; consistent first impression.           | Plan |
| Theme toggle placement    | Authenticated nav only, default light + `--prefersdark`            | Guest layout has no nav; `--prefersdark` covers guests via OS preference.     | Plan |

## Scope

**In scope:** daisyUI install + theme config; 5 `ui/*` components; theme toggle in nav; full semantic-token
conversion of all 14 views + 2 layouts + nav; branded landing; lockstep update of `app.js` + the 3 coupled
deadline-test assertions.

**Out of scope:** any controller/model/migration/route change; new functional behaviour; responsive
redesign; custom theme block; JS framework changes; edits to tests other than the deadline-pressure class
strings.

## Architecture / Approach

Bottom-up. Install daisyUI and configure `@plugin 'daisyui' { themes: light --default, dark --prefersdark; }`
in `app.css` (same mechanism the file already uses). Build 5 anonymous Blade components that wrap daisyUI
class recipes, then restyle views to call them. The single risk concentration is the venture-detail toggle
island: its `data-*` selectors are a frozen contract, and the deadline-pressure tokens are coupled across
Blade + `app.js` + tests — those move together in one phase.

## Phases at a Glance

| Phase                                          | What it delivers                                                            | Key risk                                                        |
| ---------------------------------------------- | --------------------------------------------------------------------------- | --------------------------------------------------------------- |
| 1. Foundation + components + guest surfaces    | daisyUI installed, themes wired, 5 components, theme toggle, layouts, auth, landing | Component API must be right — every later view depends on it.   |
| 2. Venture list & detail (JS-coupled)          | Detail + list restyled; pressure classes → daisyUI; `app.js` + tests in lockstep | Toggle island selectors / pressure-class triple-coupling.       |
| 3. Remaining forms                             | venture create, step create/edit, AI-suggestion preview, expense create/edit | Preserve `suggestions[i][body]`/`[keep]` field names (asserted). |

**Prerequisites:** S-01–S-06 shipped (all surfaces exist). daisyUI v5 + Node toolchain present.
**Estimated effort:** ~3 sessions, one per phase.

## Open Risks & Assumptions

- The exact daisyUI token strings rendered in `ventures/show.blade.php` must match the rewritten
  `ShowVentureDeadlineTest` assertions character-for-character — write Blade + test together.
- Wrapping the toggle markup in components must not move `[data-step-body]`/`[data-deadline-badge]` out of the
  parent/child shape `app.js` walks; if the body "done" token changes, `app.js` changes with it.
- Assumes stock `light`/`dark` contrast is acceptable without a custom palette (revisit only if a surface
  reads poorly).

## Success Criteria (Summary)

- Every surface renders consistently in both light and dark themes, with a working no-JS theme toggle.
- Completion toggle, deadline badges, progress text, total cost, and delete confirmations behave exactly as
  before (NFR(edit-latency) preserved).
- `composer run test` green (only the deadline-pressure class assertions changed); `npm run build` compiles.
