# Modernize the UI with daisyUI — Implementation Plan

## Overview

Restyle every shipped surface of GOAITracker onto **daisyUI v5** (running on the existing
Tailwind v4 + Vite pipeline), with a **light/dark theme** switched purely in CSS via a daisyUI
`theme-controller`, and **no behavioural regressions**. A small set of reusable anonymous Blade
components (`resources/views/components/ui/*`) DRYs the restyle across 14 views, 2 layouts, and the
navigation partial. The slice introduces no new functional requirement; it is a UX-quality pass.

This is roadmap slice **S-07** (`context/foundation/roadmap.md`). The three framing decisions are
already locked there: light+dark via pure-CSS `theme-controller`, reusable Blade components, stock
daisyUI themes (no custom theme block).

## Current State Analysis

- **Pipeline:** Vite 8 + Tailwind v4 via `@tailwindcss/vite`. `resources/css/app.css` already uses
  the Tailwind v4 `@import 'tailwindcss';` + `@theme {}` mechanism — daisyUI plugs into the same
  file with `@plugin`. `package.json` has `tailwindcss@^4` + `vite@^8`; **no daisyUI yet**.
- **Views (14):** `welcome` (still the **Laravel default scaffold** with hardcoded light/dark hex),
  `layouts/{app,guest,navigation}`, `auth/{login,register}`, `ventures/{index,create,show}`,
  `steps/{create,edit}`, `steps/suggestions/preview`, `expenses/{create,edit}`.
- **Styling today:** raw Tailwind utilities throughout — `bg-white`, `bg-gray-100`, `text-gray-{500..900}`,
  the repeated button pattern `inline-flex items-center px-4 py-2 bg-gray-800 … rounded-md … text-white …`,
  and the repeated input pattern `block mt-1 w-full border-gray-300 rounded-md shadow-sm`. None of these
  adapt to a theme — in a daisyUI dark theme a `bg-white` card stays white. **Full conversion to daisyUI
  semantic tokens (`base-100`, `base-200`, `base-content`, `primary`, …) is required for dark mode to work**
  (decision: full conversion across all surfaces).
- **JS island (`resources/js/app.js`):** a single `DOMContentLoaded` handler driving the
  completion-toggle. It is selector- and `data-*`-driven and load-bearing for NFR(edit-latency):
  - `form[data-toggle-completion]`, `input[type="checkbox"]`, `[data-step-body]`, `[data-deadline-badge]`,
    `[data-progress-text]`, `meta[name="csrf-token"]`.
  - On toggle it mutates `[data-step-body]` classes `line-through` + `text-gray-400`, and toggles the
    space-separated tokens read from `deadlineBadge.dataset.pressureClass`.
- **Triple-coupled deadline-pressure classes:** the literal tokens `text-red-600 font-medium` /
  `text-amber-700 font-medium` live in **three** places that must change together:
  1. `ventures/show.blade.php` — the `data-pressure-class` attribute + the render-time applied class.
  2. `resources/js/app.js` — `classList.toggle(cls, …)` over those tokens.
  3. Tests — `tests/Feature/Ventures/ShowVentureDeadlineTest.php` asserts the exact strings
     `text-amber-700`, `text-xs text-gray-500 text-red-600 font-medium`, and
     `data-pressure-class="text-red-600 font-medium"` / `data-pressure-class=""`.
- **Other test couplings (text/attribute, NOT class — preserved by keeping copy & form field names):**
  - `ListVenturesTest` → `assertSeeText('Deadline pressure')`, `assertSee(route('ventures.create'))`,
    `assertSeeText("A's venture")`, `assertSeeText("haven't created any ventures yet")`.
  - `VentureTotalCostTest` → `assertSee('Total: 24.75')` (the `Total: ` label + value must survive).
  - `ShowVentureProgressTest` → `assertSeeText('2 of 4 completed')` (the `[data-progress-text]` copy).
  - `SuggestExtensionTest` → `assertSee('name="suggestions[i][body]"')` + `[keep]` (form field names,
    not styling — preserved).

### Key Discoveries

- `resources/css/app.css:1` already uses Tailwind v4 `@import` + `@theme` — daisyUI's `@plugin` slots in
  the same file; no `tailwind.config.js` needed (confirmed current for daisyUI v5 via Context7).
- daisyUI v5's `dark` theme does **not** auto-bind to Tailwind's `dark:` variant. We do not rely on
  `dark:` utilities; we use semantic tokens that re-resolve per active `data-theme`. Stock `light`/`dark`
  themes are used as-is (no `@custom-variant` mapping, no custom theme block — matches the locked decision).
- The `theme-controller` is a checkbox with `class="theme-controller"` and `value="<theme-name>"`; checking
  it sets `data-theme` on the page with zero JS — so the existing islands stay untouched.
- `routes/web.php:10` renders `welcome` at `/`; it is the only public/guest landing and is replaced
  (decision: branded landing).
- `layouts/guest.blade.php` has **no navigation partial** — the theme toggle on guest screens, if wanted,
  would need separate placement. Decision: toggle lives in the authenticated nav only (default light +
  `--prefersdark`); guest screens inherit the system-preferred theme via `--prefersdark`.

## Desired End State

Every surface renders through daisyUI components and semantic theme tokens. A sun/moon `theme-controller`
in the authenticated navigation flips light↔dark with no page reload and no JS; guest screens honour the
OS preference via `--prefersdark`. The completion toggle, deadline badges, progress text, and inline
delete confirmations all behave exactly as before. `composer run test` is green (with the three
`ShowVentureDeadlineTest` class assertions updated to the new semantic tokens). `npm run build` compiles
with daisyUI active.

Verification: load each surface in both themes; toggle a step's completion and confirm strike-through +
deadline-emphasis flip live and restore on un-complete; confirm the venture-list deadline marker, total
cost, and progress text still render; run the full test suite.

## What We're NOT Doing

- No new functional requirement, route, controller, model, migration, or DB change.
- No change to any controller logic, FormRequest, validation rule, or the toggle JSON response contract.
- No responsive redesign / new layouts / new navigation structure beyond adding the theme toggle.
- No custom daisyUI theme block — stock `light` / `dark` only.
- No new JS framework, Alpine, Livewire, or build-tooling swap — the one vanilla island stays vanilla.
- No accessibility audit beyond preserving existing semantics (labels, `<noscript>` fallback).
- No animation/transition work beyond what daisyUI components ship by default.
- No edits to tests other than the deadline-pressure class strings in `ShowVentureDeadlineTest`
  (all other assertions are preserved by keeping copy and field names intact).

## Implementation Approach

Bottom-up. Phase 1 installs daisyUI, configures themes, builds the 5 shared components, adds the theme
toggle, converts the two layouts + nav, and restyles the guest surfaces (auth + new landing) — this proves
the pipeline and the component API end-to-end on low-risk pages before touching the JS-coupled views.
Phase 2 is the newralgic pass: the venture list + detail, where the toggle island, deadline badge, and the
triple-coupled pressure classes live — Blade + `app.js` + the coupled tests move in lockstep. Phase 3
restyles the remaining forms through the now-proven components.

The pressure-class conversion (decision: convert to daisyUI semantic): the literal Tailwind tokens become
daisyUI text-color utilities — overdue → `text-error`, imminent → `text-warning`, and the muted base date
text → `text-base-content/60` (replacing `text-xs text-gray-500`). The `data-pressure-class` attribute
carries the new emphasis tokens (`text-error font-medium` / `text-warning font-medium`), `app.js` toggles
them unchanged in mechanism, and the three `ShowVentureDeadlineTest` assertions are rewritten to the new
strings in the same phase.

## Critical Implementation Details

- **Lockstep edit (Phase 2).** The deadline-pressure restyle MUST change `ventures/show.blade.php`,
  `resources/js/app.js`, and `tests/Feature/Ventures/ShowVentureDeadlineTest.php` in the same commit. The
  `data-pressure-class` value, the render-time applied class, the JS `classList.toggle` token source, and
  the test's `assertSee(...)` strings are one contract — editing any one alone breaks either the live
  toggle or CI.
- **Toggle island selectors are a frozen contract.** Wrapping the checkbox/step-body/badge in a daisyUI
  component is fine ONLY if `form[data-toggle-completion]`, the `input[type="checkbox"]`, `[data-step-body]`,
  `[data-deadline-badge]`, `[data-progress-text]`, and `meta[name="csrf-token"]` remain present and in the
  same parent/child relationship `app.js` walks (`form.querySelector(...)`). The strike-through tokens the
  JS writes (`line-through`, `text-gray-400`) must stay the tokens the JS writes — if the body's muted/done
  styling moves to a daisyUI token, update `app.js` in lockstep too.
- **Preserved copy & field names.** Keep the exact strings `Total: `, `X of Y completed`,
  `Deadline pressure`, `haven't created any ventures yet`, and the `suggestions[i][body]` / `[keep]` field
  names — other tests assert on them as text/attributes, not styling.
- **`@source` globs.** `app.css` already has `@source '../**/*.blade.php'`, so the new
  `components/ui/*.blade.php` and their daisyUI classes are picked up by Tailwind's content scan without a
  config change.

---

## Phase 1: Foundation, shared components, layouts & guest surfaces

### Overview

Install daisyUI, wire light/dark themes, build the 5 reusable components, add the CSS theme toggle, convert
the two layouts + navigation to semantic tokens, and restyle the guest-facing surfaces (login, register,
and a new branded landing replacing the Laravel scaffold). No JS-coupled views are touched here.

### Changes Required

#### 1. Add daisyUI dependency

**File**: `package.json`

**Intent**: Add daisyUI v5 to devDependencies so the Vite/Tailwind build can load it as a plugin.

**Contract**: `"daisyui": "^5"` (or `@latest` resolved to a v5 line) under `devDependencies`. Run
`npm install` so the lockfile updates. No script changes.

#### 2. Configure daisyUI + themes

**File**: `resources/css/app.css`

**Intent**: Register the daisyUI plugin with stock light (default) + dark (prefers-dark) themes, keeping
the existing Tailwind import, `@source` globs, and `@theme` font block.

**Contract**: Add after the `@import 'tailwindcss';` line:
`@plugin 'daisyui' { themes: light --default, dark --prefersdark; }`. No `@custom-variant`, no custom theme
block. The existing `@source` and `@theme` blocks stay.

#### 3. Reusable UI components

**File**: `resources/views/components/ui/{button,card,input,alert,badge}.blade.php`

**Intent**: Five anonymous Blade components that encapsulate the daisyUI class recipes so the restyle is DRY
and consistent. Each uses `@props` for variants and `$attributes->merge([...])` so callers can still pass
`type`, `href`, `name`, `id`, etc.

**Contract**:
- `ui.button` — props: `variant` (`primary`|`ghost`|`error`, default `primary`), `type` (default `submit`);
  renders `<button class="btn btn-{variant}">` or an `<a class="btn …">` when `href` is passed. Replaces the
  repeated `inline-flex … bg-gray-800 …` block and the text-link buttons (`+ Add step`, `Suggest more`, etc.
  use `variant=ghost` → `btn btn-ghost btn-sm`).
- `ui.card` — wraps content in daisyUI `card bg-base-100 shadow-sm` with a `card-body`; optional `title` prop
  renders a `card-title`/section heading. Replaces the repeated `bg-white … shadow-sm sm:rounded-lg` +
  inner `p-6` wrapper.
- `ui.input` — props: `label`, `name`, `type` (default `text`), `error` (defaults to the bag for `name`);
  renders a daisyUI `fieldset`/`label` + `input input-bordered` (or `textarea textarea-bordered` when
  `type=textarea`) + the `@error` message. Replaces the `label` + `block mt-1 w-full border-gray-300 …` +
  `@error` triad repeated across all 6 forms.
- `ui.alert` — props: `variant` (`info`|`warning`|`success`|`error`); renders daisyUI `alert alert-{variant}`.
  Replaces the amber `ai_unavailable` notice (`alert alert-warning`) and the green `status` notice
  (`alert alert-success`).
- `ui.badge` — props: `variant`; renders `badge badge-{variant}`. Used by the deadline marker in Phase 2 and
  available to forms now.

#### 4. Theme controller in navigation

**File**: `resources/views/layouts/navigation.blade.php`

**Intent**: Add a pure-CSS sun/moon theme toggle to the authenticated nav and convert the nav chrome to
semantic tokens.

**Contract**: A daisyUI `swap swap-rotate` label wrapping `<input type="checkbox" class="theme-controller"
value="dark" />` + sun/moon SVGs (swap-off/swap-on). Nav background → `navbar bg-base-100` with
`border-base-300`; brand/text → `text-base-content`; the "Sign out" button → `ui.button variant=ghost`.
Default theme is `light` via the `app.css` config; an unchecked controller leaves the page on the default
(or `--prefersdark` when the OS prefers dark). No JS.

#### 5. Convert the two layouts

**File**: `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php`

**Intent**: Move the page shells off raw gray utilities onto semantic tokens so both themes render correctly.

**Contract**: `app` — `body` `bg-base-100`, content wrapper `bg-base-200` (the page field), `@hasSection('header')`
header → `bg-base-100` + `border-base-300`. `guest` — `bg-base-200` page, the centered card → `ui.card` (or
`card bg-base-100`), brand link → `text-base-content`. Keep `@vite([...])`, the `csrf-token` meta, and the
`min-h-screen` structure intact.

#### 6. Restyle auth surfaces

**File**: `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`

**Intent**: Render the auth forms through `ui.input` / `ui.button` / `ui.alert`.

**Contract**: Inputs via `ui.input` (email, password, confirm); the "Remember me" checkbox → daisyUI
`checkbox checkbox-sm` inside a `label`; submit → `ui.button`; the "Need an account?" / "Already registered?"
links → `link link-hover` (or `btn btn-ghost btn-sm`); `session('status')` → `ui.alert variant=success`.
Keep all `name`, `id`, `autocomplete`, `required` attributes and the validation `@error` wiring.

#### 7. Branded landing replacing the scaffold

**File**: `resources/views/welcome.blade.php` (rewritten)

**Intent**: Replace the Laravel default scaffold with a small GOAITracker hero on the daisyUI theme.

**Contract**: A daisyUI `hero bg-base-200 min-h-screen` with a `hero-content` card: app name, one-line value
prop, and `Log in` / `Register` CTAs via `ui.button` (guarded by `@guest` / `@auth` → authed users get a
`Dashboard` CTA to `route('dashboard')`). Uses `@vite(['resources/css/app.css', 'resources/js/app.js'])` so
the theme + fonts load. No reference to the Laravel docs/Laracasts scaffold remains.

### Success Criteria

#### Automated Verification

- `npm install` completes and `package.json` lists daisyUI v5.
- `npm run build` compiles with no Tailwind/daisyUI errors.
- `composer run test` is green (Phase 1 touches no class-asserting test; auth/list/landing assertions on
  copy + routes still pass).

#### Manual Verification

- Login, register, and the new landing render correctly in **both** light and dark themes.
- The nav theme toggle flips light↔dark instantly with no reload and no console error.
- With OS set to dark, guest screens load dark via `--prefersdark`.
- No raw `bg-white` / `bg-gray-*` "white card on dark" artifacts on any Phase 1 surface.

**Implementation Note**: After Phase 1 passes automated verification, pause for manual confirmation before
starting Phase 2.

---

## Phase 2: Venture list & detail (JS-coupled — newralgic)

### Overview

Restyle `ventures/index` and `ventures/show` — the surfaces carrying the completion-toggle island, the
deadline badge, the progress text, the list-level deadline marker, and the total-cost line. Convert the
triple-coupled deadline-pressure classes to daisyUI semantic tokens, updating `app.js` and the coupled
`ShowVentureDeadlineTest` in the same pass.

### Changes Required

#### 1. Venture detail view

**File**: `resources/views/ventures/show.blade.php`

**Intent**: Restyle the three cards (Description, Steps, Expenses) onto `ui.card`/`ui.alert`/`ui.button`, and
convert the deadline-pressure tokens to daisyUI semantics — **without** disturbing the JS island contract.

**Contract**:
- Cards → `ui.card`; `ai_unavailable` notice → `ui.alert variant=warning`.
- **Preserve exactly**: `form[data-toggle-completion]`, the `<input type="checkbox" name="is_completed">`,
  `[data-step-body]`, `[data-progress-text]` (copy `{{ $completed }} of {{ $total }} completed`),
  `[data-deadline-badge]`, the `meta` csrf tag (in layout), the `<noscript>` Save button, and the inline
  `onsubmit="return confirm('Delete this step?')"` guard.
- **Pressure-class conversion**: the muted base date class `text-xs text-gray-500` → `text-xs text-base-content/60`;
  the emphasis match map becomes `overdue => 'text-error font-medium'`, `imminent => 'text-warning font-medium'`.
  `data-pressure-class` carries the new emphasis tokens; the render-time applied class stays gated on
  `! $step->is_completed`. The checkbox → daisyUI `checkbox`; step-body done styling stays `line-through` +
  the muted token the JS writes (see app.js change). Buttons/links → `ui.button variant=ghost` / `link`.
- Expenses list rows → daisyUI list styling on `base` tokens; `Total: {{ number_format(...) }}` label preserved.

#### 2. Toggle island JS

**File**: `resources/js/app.js`

**Intent**: Keep the island mechanism identical; only update the literal class tokens it writes so they match
the new daisyUI markup.

**Contract**: If the step-body "done" styling changes token (e.g. `text-gray-400` → `text-base-content/40`),
update the `bodyEl.classList.toggle(...)` tokens to match `show.blade.php`. The `pressureClass` path is
mechanism-unchanged (it reads `dataset.pressureClass` and toggles whatever tokens are there) — it works with
the new `text-error font-medium` / `text-warning font-medium` values automatically. No change to fetch, CSRF,
response parsing, or `[data-progress-text]` update.

#### 3. Venture list view

**File**: `resources/views/ventures/index.blade.php`

**Intent**: Restyle the list onto `ui.card` + daisyUI list/badge while preserving the shared row-metadata
contract and the asserted copy.

**Contract**: Outer → `ui.card`; empty-state CTA + "+ New venture" → `ui.button`; delete → `ui.button
variant=error btn-sm` inside the form keeping `onsubmit="return confirm('Delete this venture? …')"`. Row
metadata stack preserved in order: line 1 progress (`X of Y steps completed` / `—`), line 2
`Total: {{ number_format(...) }}`, line 3 deadline marker. The deadline marker → `ui.badge variant=error`
(or `text-error`) but **must still contain the text `Deadline pressure`** (asserted). Keep
`route('ventures.create')` href and `A's venture` title rendering.

#### 4. Update coupled deadline tests

**File**: `tests/Feature/Ventures/ShowVentureDeadlineTest.php`

**Intent**: Re-point the three class-string assertions at the new daisyUI semantic tokens, matching the Blade
change in #1. Behavioural assertions (presence of `data-deadline-badge`, the `Overdue` text, the empty
`data-pressure-class=""` for far-future) are unchanged.

**Contract**: `assertSee('text-amber-700')` → `assertSee('text-warning')`; the overdue applied-class string
`'text-xs text-gray-500 text-red-600 font-medium'` → `'text-xs text-base-content/60 text-error font-medium'`;
`data-pressure-class="text-red-600 font-medium"` → `data-pressure-class="text-error font-medium"`. Keep the
`Overdue` text and `data-pressure-class=""` assertions. (Exact final strings must mirror whatever #1 renders —
write them together.)

### Success Criteria

#### Automated Verification

- `composer run test` is green — including the rewritten `ShowVentureDeadlineTest`, and the unchanged
  `ToggleStepTest`, `ShowVentureProgressTest`, `ListVenturesTest`, `VentureTotalCostTest`.
- `npm run build` compiles.

#### Manual Verification

- Toggling a step's completion flips strike-through + deadline-emphasis live (no reload) and **restores**
  emphasis on un-complete — in both themes (NFR(edit-latency) preserved).
- With JS disabled, the `<noscript>` Save button still persists the toggle (302 fallback).
- Venture list shows the deadline marker, total cost, and progress text correctly; delete confirm dialog
  still fires on both list and detail.
- Overdue → error color, imminent → warning color, completed step → muted (no emphasis) in both themes.

**Implementation Note**: After Phase 2 passes automated verification, pause for manual confirmation before
starting Phase 3.

---

## Phase 3: Remaining forms

### Overview

Restyle the create/edit forms and the AI-suggestion preview through the now-proven components.

### Changes Required

#### 1. Venture create

**File**: `resources/views/ventures/create.blade.php`

**Intent**: Render title/description through `ui.card` + `ui.input` + `ui.button`.

**Contract**: Title `ui.input`, description `ui.input type=textarea`, the "richer descriptions…" hint as
`text-sm text-base-content/60`, submit `ui.button`. Keep `maxlength`, `required`, `old()` values.

#### 2. Step create / edit

**File**: `resources/views/steps/create.blade.php`, `resources/views/steps/edit.blade.php`

**Intent**: Render body + optional deadline through the shared components.

**Contract**: Body `ui.input type=textarea`; deadline `ui.input type=date` with the optional hint; Cancel →
`link`/`btn btn-ghost`, submit → `ui.button`. Keep `old('body', …)`, `old('deadline', …)`, `@method('PATCH')`
on edit, and all field names.

#### 3. AI-suggestion preview

**File**: `resources/views/steps/suggestions/preview.blade.php`

**Intent**: Restyle the keep/uncheck list onto daisyUI checkboxes + card.

**Contract**: Wrap in `ui.card`; each row's checkbox → daisyUI `checkbox`; **preserve the field names**
`suggestions[{{ $i }}][keep]` and the hidden `suggestions[{{ $i }}][body]` (asserted by `SuggestExtensionTest`),
the `checked` default, and the `@error('suggestions')` message → `ui.alert variant=error`. Cancel/submit →
`ui.button`.

#### 4. Expense create / edit

**File**: `resources/views/expenses/create.blade.php`, `resources/views/expenses/edit.blade.php`

**Intent**: Render amount/description/date through the shared components.

**Contract**: Amount `ui.input type=number` (`step=0.01 min=0`), description `ui.input type=textarea`, date
`ui.input type=date`; Cancel → `link`, submit → `ui.button`. Keep `old(...)` values incl.
`old('date', now()->toDateString())` and `old('amount', $expense->amount)`, field names, and `@method('PATCH')`
on edit.

### Success Criteria

#### Automated Verification

- `composer run test` is green — including `SuggestExtensionTest` (field-name assertions) and all
  step/expense feature tests.
- `npm run build` compiles.

#### Manual Verification

- All six forms render and submit correctly in both themes; validation errors display via `ui.input`/`ui.alert`.
- AI-suggestion preview keep/uncheck still persists the chosen rows; cancel returns to the venture.
- No raw white-on-dark artifacts remain on any surface; visual consistency across all 14 views.

**Implementation Note**: After Phase 3 passes automated verification, pause for final manual confirmation.

---

## Testing Strategy

### Automated

- The full `composer run test` suite is the regression gate. Only `ShowVentureDeadlineTest` is intentionally
  edited (class-string assertions → daisyUI tokens, Phase 2). Every other assertion (toggle JSON,
  progress/total/marker copy, suggestion field names, isolation 404s) must remain green untouched — that is
  the proof the restyle is behaviourally inert.
- `npm run build` per phase confirms daisyUI classes resolve and the content scan picks up the new components.

### Manual

1. In each theme (light, dark): walk login → register → landing → venture list → venture detail → each form.
2. On venture detail: toggle a step complete and incomplete; confirm strike-through + deadline emphasis flip
   live and restore, and `[data-progress-text]` updates without reload.
3. Disable JS; confirm the `<noscript>` Save fallback persists a toggle and delete confirms still fire.
4. Verify overdue (error) vs imminent (warning) vs completed (muted) badge colors in both themes.
5. Toggle the theme switch repeatedly; confirm no flespecially flash/reset of island state on the same page.

## Performance Considerations

daisyUI is CSS-only; no runtime JS cost added. The completion-toggle remains a single `fetch` with the same
JSON contract — NFR(edit-latency) is structurally unchanged. The only build-time delta is a larger compiled
CSS bundle (daisyUI utilities), which is static-served and cached.

## Migration Notes

No data migration. `welcome.blade.php` is rewritten in place (the `/` route is unchanged). If a future slice
wants a custom brand palette, it slots into the existing `@plugin 'daisyui'` block as a custom theme without
touching component call sites.

## References

- Roadmap slice: `context/foundation/roadmap.md` (S-07, decisions resolved 2026-06-03)
- daisyUI v5 + Tailwind v4 setup + theme-controller — verified via Context7 (`/saadeghi/daisyui`)
- JS island contract: `resources/js/app.js`
- Coupled tests: `tests/Feature/Ventures/ShowVentureDeadlineTest.php`,
  `tests/Feature/Ventures/ListVenturesTest.php`, `tests/Feature/Ventures/VentureTotalCostTest.php`,
  `tests/Feature/Ventures/ShowVentureProgressTest.php`, `tests/Feature/Steps/SuggestExtensionTest.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Foundation, shared components, layouts & guest surfaces

#### Automated

- [x] 1.1 npm install completes and package.json lists daisyUI v5
- [x] 1.2 npm run build compiles with no Tailwind/daisyUI errors
- [x] 1.3 composer run test is green

#### Manual

- [x] 1.4 Login, register, landing render in both light and dark themes
- [x] 1.5 Nav theme toggle flips light↔dark instantly, no reload, no console error
- [x] 1.6 Guest screens load dark via --prefersdark when OS prefers dark
- [x] 1.7 No white-card-on-dark artifacts on any Phase 1 surface

### Phase 2: Venture list & detail (JS-coupled — newralgic)

#### Automated

- [ ] 2.1 composer run test green incl. rewritten ShowVentureDeadlineTest + unchanged Toggle/Progress/List/TotalCost tests
- [ ] 2.2 npm run build compiles

#### Manual

- [ ] 2.3 Toggle flips strike-through + deadline emphasis live and restores on un-complete (both themes)
- [ ] 2.4 JS-disabled <noscript> Save fallback persists the toggle (302)
- [ ] 2.5 List shows deadline marker, total cost, progress text; delete confirm fires on list and detail
- [ ] 2.6 Overdue→error, imminent→warning, completed→muted in both themes

### Phase 3: Remaining forms

#### Automated

- [ ] 3.1 composer run test green incl. SuggestExtensionTest field-name assertions + step/expense tests
- [ ] 3.2 npm run build compiles

#### Manual

- [ ] 3.3 All six forms render/submit in both themes; validation errors display via ui.input/ui.alert
- [ ] 3.4 AI-suggestion preview keep/uncheck persists chosen rows; cancel returns to venture
- [ ] 3.5 No white-on-dark artifacts; visual consistency across all 14 views
