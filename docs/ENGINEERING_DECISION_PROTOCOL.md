# Engineering Decision Protocol

Use this protocol for WP CareGrid milestones whenever implementation exposes a new decision:

1. **A — technical detail:** resolve within engineering using the least destructive, reversible, fail-closed option that fits existing architecture and preserves tenant isolation, auditability and observability. Do not interrupt the Owner for routine implementation choices.
2. **B — Owner product/policy decision:** if not already approved, include it in one consolidated questionnaire with a recommended default, alternatives, customer/operational impact, reversibility, required reviews and whether it blocks safe work.
3. **C — legal/privacy decision:** do not infer a legal conclusion. Represent the state as provisional and `WAIT_LEGAL` until qualified review is recorded.
4. **D — accounting/tax decision:** do not infer a statutory period or treatment. Represent the state as provisional and `WAIT_ACCOUNTING` until qualified review is recorded.
5. **E — security policy decision:** preserve the stronger safeguard while unresolved; ask in the consolidated questionnaire only when the choice changes security posture.
6. **F — provider/contract fact:** verify from an authoritative provider or contract source when safe. Keep unknown facts unknown; do not turn them into legal conclusions.

If a decision does not block the safe current behavior, continue implementation and store the unresolved value explicitly as blocked/provisional. Pause only when a genuinely unresolved choice is necessary to avoid unsafe behavior. Never silently convert an Owner's technical/product approval into legal, accounting, tax or security approval.

For all browser/computer-use validation, prefer a fresh isolated browser context. Before reading content, verify hostname, application, environment and authenticated identity. Never inspect an unknown pre-existing tab. If those facts cannot be verified, stop browser inspection and use authenticated HTTP, ordinary application/controller/service paths, approved CLI or read-only API/database checks. Browser-tool failure is not product failure. Do not copy unrelated personal data into evidence.
