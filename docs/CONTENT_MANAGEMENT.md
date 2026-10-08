# Content management

Structured, owner-editable content for the public marketing pages: homepage sections, navigation, footer, plan wording and SEO metadata, per locale, with draft and published states. It is not a page builder: the page structure, layout and icons stay in code; the owner edits the words and links of a fixed set of sections. Owner area overview: OWNER_ADMIN.md.

## Structure

`App\Content\Schema\ContentSchema` defines every section and its fields. The editor form is generated from the same definitions, so the owner cannot edit a field the page does not use, and the page cannot read a field the editor does not validate.

| Group | Sections |
| --- | --- |
| homepage | `home.hero`, `home.services`, `home.why`, `home.update_process`, `home.pricing_intro`, `home.dashboard`, `home.licenses`, `home.woocommerce`, `home.development`, `home.how`, `home.activity`, `home.faq`, `home.final_cta` |
| site | `site.navigation`, `site.footer` |
| pricing | `pricing.{wordpress,woocommerce}.{basic,business,secure}` (plan wording, see PRICING_ADMIN.md) |
| seo | `seo.home`, `seo.pricing` (edited under `/owner/seo`) |

Field types (`FieldType`): `text`, `textarea`, `rich_text` (only the FAQ answer), `url`, `boolean`, `number`, `select`, `repeater` (lists the owner can add to, remove from and reorder: FAQ items, navigation items, plan features) and `group` (a fixed set of cards tied to the page structure and its icons: services, issue cards, process steps; editable and switchable, not added to).

Examples: the hero has eyebrow, title, description, two button labels and links, and three trust indicators; services have a title, text and "shown" switch per card; the FAQ has items with question, answer, "shown" and position; navigation items have label, URL, "shown" and position; SEO has title, description, canonical URL, robots and Open Graph title and description.

### Storage

One table, `content_entries`: one row per section and locale (`key`, `locale`), with `draft` and `published` JSON holding that section's validated fields, and who/when for each. A whole page is not one opaque blob: each section is its own record, typed by the schema. FAQ and navigation items live inside their section (ordered lists with positions) so that they are drafted, published and translated together with it; a table per item type would add tables without adding anything the schema does not already check.

## Draft and publish

`App\Content\ContentEditor`:

- **Save draft**: validates types, lengths and links (required fields may be empty) and stores the draft. The public page does not change.
- **Publish**: validates every required field, stores the values as published, clears the draft and the published-content cache. The next request shows the new content.
- **Unpublish**: removes the published version; the values are kept as the draft. The public page falls back (below).
- **Discard draft**.

States shown in the editor (`ContentState`): *Published*, *Unpublished changes* (published plus a newer draft), *Draft only*, *Built-in text* (nothing written yet, the built-in copy from `lang/{locale}` is shown), *Missing* (no content of its own in this language: the public page shows the fallback language).

There is no review workflow, scheduling or version history yet.

## Localization

Locales are configured in `config/content.php` (`en`, `hu`); adding one there is enough for the editor. Each locale has its own records; nothing is copied or translated automatically. Built-in copy exists where `lang/{locale}` has the texts: today English and Hungarian, so every section is *Built-in text* in both languages and nothing is *Missing*. A language added to `config/content.php` without translation files has every section *Missing* until it is published (the public page then shows the fallback language, section by section).

The editor shows every language with its state for the section being edited, and, when editing a non-fallback language, the fallback language's text under each field as a reference (never copied into the field).

### Public fallback policy

Public pages use the application locale (`APP_LOCALE`). For each section, `ContentRepository` uses the first of:

1. published content in the page's locale;
2. built-in copy in the page's locale (`lang/{locale}/home.php` …), if it exists;
3. the same two steps in the fallback locale (`fallback_locale`, English).

A section is always taken whole from one source and one language; fields are never mixed across languages. A section shown in the fallback language gets a `lang="en"` attribute, so browsers and screen readers know. A published section always has all required fields (publishing refuses otherwise); a field added to the schema later is empty in older content, not filled from another language.

Interface strings that are not content (pricing table labels, form labels, the customer portal) come from the translation files; Laravel falls back to English for keys a locale lacks. Which language a visitor sees, the selector and the currency rule: [LOCALIZATION.md](LOCALIZATION.md).

### Hungarian copy

`lang/hu/` holds the Hungarian public site, with exactly the keys of the English files (`HungarianContentTest` fails on a missing key, an empty or untranslated text, or a lost `:placeholder`): `home.php` (all 13 homepage sections, navigation, footer, homepage SEO), `pricing.php` (plan wording and pricing table), `checkout.php` (pricing-page SEO, the order pages), `dashboard.php` and `status.php` (the demo dashboard on the homepage), `countries.php` (billing countries, Hungarian names in Hungarian order) and, in `account.php`, only the few keys the public order pages use. The customer login and portal are Hungarian too since the bilingual milestone (LOCALIZATION.md); the owner and operator areas and the emails stay English.

Conventions: brand and product names (WP CareGrid, WordPress, WooCommerce, Stripe) and the plan names Basic, Business and Secure are not translated; the claims are exactly those of the English text; prices and amounts always come from `config/pricing.php` (the Hungarian text only says "havi/éves", "két hónap ingyenes", "végleges árak: áfát nem számítunk fel"). Terms: karbantartás (maintenance), mentés (backup), frissítés (update), bővítmény (plugin), téma (theme), licenc (license), vezérlőpult (dashboard), tevékenységnapló (activity log), funkcionális ellenőrzés (functional check). The owner can still replace any Hungarian section by publishing their own text in the editor; that always wins over the built-in copy.

## Preview

"Preview" opens `/owner/preview/{locale}`: the homepage with that locale's drafts (published content where there is no draft), including draft plan wording. It requires a signed in owner **and** a signed, temporary URL (`CONTENT_PREVIEW_MINUTES`, 15 by default); unsigned, altered or expired links are refused. The response is `no-store`, `noindex, nofollow` and carries a visible "draft preview" banner. Drafts are rendered in a repository of their own and never enter the shared published-content cache.

## Output safety

- Every text field is plain text, rendered with Blade escaping (`{{ }}`). Control characters are removed when saving; single-line fields lose line breaks.
- The only HTML is the FAQ answer (`rich_text`), sanitised by `HtmlSanitizer` when saved **and** when rendered: `p`, `br`, `strong`/`b`, `em`/`i`, `ul`, `ol`, `li`, and `a` with a safe `href` (given `rel="nofollow noopener noreferrer"`). Scripts, styles, iframes, SVG, forms, images and similar are dropped with their content; other tags are unwrapped; every other attribute (event handlers, `style`, `class`, `target`) is removed; comments are dropped.
- Links (`url` fields and links in rich text) must be an anchor (`#pricing`), a path on this site (`/pricing`), an http(s) URL without credentials or `mailto:`. Refused: `javascript:`, `data:`, `vbscript:`, `file:`, protocol-relative `//host`, backslashes, whitespace and control characters. Links are checked again when rendered; an unsafe stored value becomes `#`.
- Canonical URLs must be http(s) on the application's own host (`APP_URL`).
- Unknown fields are dropped; only the schema's fields are stored.

Tests cover HTML, script and attribute injection, unsafe URLs, rich text sanitising and stored unsafe values.

## SEO

`/owner/seo` lists the pages (home, pricing) per locale. Fields: title, description, canonical URL, robots (`index,follow` default, `noindex,follow`, `index,nofollow`, `noindex,nofollow`), Open Graph title and description (empty: title and description). Localized routes accept a canonical override only when it exactly matches the generated canonical for that locale; a mismatching existing value is ignored and a sanitized warning is logged. Metadata applies only to that page's tags. See [SEO_INDEXING.md](SEO_INDEXING.md) for indexing and sitemap gates.

## Navigation and footer

`site.navigation`: menu items (label, URL, shown, order) used in the header, the mobile menu and the footer's product column, plus the login and "get started" button labels. `site.footer`: the footer description, descriptor, column headings and link labels. Footer link targets (login, contact, legal pages) stay in `config/marketing.php`.

## Caching

Published content is read once per request from the cache key `content.published` (`Cache::rememberForever`), which `ContentEditor` clears on every publish and unpublish, together with the request's pricing catalogue (plan wording). Drafts never touch the cache. If the content tables cannot be read, public pages fall back to the built-in copy and a warning is logged.

## Audit

`content.created`, `content.updated` (draft saved), `content.published`, `content.unpublished`, `content.draft_discarded`, with the owner, time and `section:locale` (admin_audit_events).
