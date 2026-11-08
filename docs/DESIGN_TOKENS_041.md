# Direction 04.1 design tokens

Source of truth: `resources/css/tokens/direction-041.css` (values only, on `:root`).
Component rules live in `resources/css/m16-direction-041.css` and reference tokens by name.

## Naming

| Group | Tokens |
|---|---|
| Text and surfaces | `--m16-ink`, `-graphite`, `-muted`, `-paper`, `-paper-deep`, `-surface`, `-on-dark`, `-on-dark-muted` |
| Brand greens | `--m16-teal`, `-teal-deep`, `-mint`, `-mint-soft`, `-green-ink`, `-mint-on-dark` |
| Dark section | `--m16-surface-dark`, `-surface-dark-mid`, `-surface-dark-end` |
| Pricing tint | `--m16-tint-start`, `-tint-mid`, `-tint-end` |
| Lines | `--m16-line`, `-line-action`, `-line-card`, `-line-tint`, `-line-dark` |
| Shape, elevation, type | `--m16-radius-*`, `--m16-shadow-*`, `--m16-font-*` |

## Rules

- Add a new colour as a token first; no raw hex in `m16-direction-041.css` (enforced by `tests/js/design-tokens.test.js`, except the `#000` mask-alpha stops).
- Tokens are scope-free, so the Customer Dashboard can consume them without the `body.marketing-home` selectors, which stay homepage-only.
- Reusable component classes today: `.caregrid-041-panel`, `.caregrid-041-kicker`.
- Text pairs verified for WCAG AA: muted on paper 5.48:1, teal on paper 4.98:1, green-ink on paper 6.08:1, on-dark-muted on surface-dark 10.38:1, mint-on-dark on surface-dark 9.68:1.
- Token values were introduced without changing the rendered homepage (see the PR for the verification method).
