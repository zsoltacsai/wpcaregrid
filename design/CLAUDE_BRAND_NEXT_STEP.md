# WP CareGrid — Claude Code: Brand integration + homepage continuation

You already have the WP CareGrid homepage specification from the previous prompt.
This task adds the approved brand assets and makes the visual implementation match them closely.

## Required reference files

Read these files before changing the UI:

- design/brand/BRAND.md
- design/brand/DESIGN_TOKENS.css
- design/brand/color-palette.txt

Logo assets are in:

- design/brand/logo/wpcaregrid-horizontal-light.svg
- design/brand/logo/wpcaregrid-horizontal-dark.svg
- design/brand/logo/wpcaregrid-stacked-light.svg
- design/brand/logo/wpcaregrid-stacked-dark.svg
- design/brand/icons/wpcaregrid-favicon.svg
- design/brand/icons/wpcaregrid-app-icon.svg

The files are the approved visual direction for WP CareGrid.

## Goal

Continue the homepage implementation and bring it visually into line with the brand.

The result should look like a mature technical WordPress maintenance service, not a generic AI-generated SaaS landing page.

## Non-negotiable visual rules

Do NOT introduce:
- gradients
- glassmorphism
- neon
- blob backgrounds
- 3D illustrations
- generic stock photos
- colorful emoji-style icons
- excessive rounded cards
- giant hero typography that dominates the entire viewport
- excessive animation
- generic AI/SaaS copy

Prefer:
- dark navy text
- off-white page background
- white surfaces
- muted green for primary actions and positive status
- thin borders
- subtle shadows
- restrained 8–16px radii
- strong but practical typography
- real-looking product UI

## Logo usage

Use the supplied SVG logo assets instead of recreating the logo in CSS or text.

Header:
- use horizontal logo
- light background version

Dark CTA/footer:
- use horizontal dark-background/light logo

Mobile:
- horizontal logo where it remains readable
- stacked/icon-only option only where necessary

Favicon/app icon:
- use supplied icon assets

Do not distort, recolor, rotate or add effects to the logo.

## Typography

Use IBM Plex Sans for the interface.
Use IBM Plex Mono only for:
- timestamps
- logs
- version numbers
- IDs
- technical status metadata

Do not use monospace for body text.

## Homepage visual hierarchy

Keep the approved page structure:

1. Header
2. Hero
3. Services strip
4. Why maintenance
5. Update verification process
6. Pricing
7. Central dashboard
8. License management
9. WooCommerce care
10. Custom development
11. How it works
12. Activity log
13. FAQ
14. Final CTA
15. Footer

## Hero

Use:

H1: "WordPress maintenance, without the hassle."

Subheadline:
"WordPress and WooCommerce maintenance, backups, security checks and updates — with functional checks after important updates."

The right side should be a real HTML/CSS dashboard preview, not a flat image.

## Product UI

Build the dashboard preview from reusable Blade components where practical.

Use the same visual language that will later be used by the authenticated dashboard.

Example status values:
- Healthy
- Attention
- Critical

Demo data must be clearly demo data and must never imply real customer statistics.

## Pricing

Use these exact Hungarian prices:

WordPress:
- Basic 8 990 Ft/month, 89 900 Ft/year
- Business 18 990 Ft/month, 189 900 Ft/year
- Secure 28 990 Ft/month, 289 900 Ft/year

WooCommerce:
- Basic 14 900 Ft/month, 149 000 Ft/year
- Business 24 900 Ft/month, 249 000 Ft/year
- Secure 39 900 Ft/month, 399 000 Ft/year

Annual billing:
- communicate "2 months free"

Do not hardcode repeated markup. Keep pricing in structured data/config so Stripe Price IDs and multi-currency pricing can be added later.

## Important service message

A key product differentiator is:

Backup → Update → Check → Functional test

Make this process visually prominent.

The service is not just "plugin updating".

## License handling

Show a realistic UI example:

Plugin license
WP Rocket
LICENSE EXPIRED
Customer action required

Explain that third-party plugin/theme licenses remain the customer's responsibility and the owner is notified when a license is detected as expired or invalid.

## WooCommerce

Show:

Product → Cart → Checkout → Payment → Order → Email

Use the same minimal icon treatment throughout.

## Custom development

Show four concise cards:

- Custom plugins
- API integrations
- WooCommerce development
- Automation

This is a separate paid service from maintenance subscriptions.

## Responsive behavior

Desktop: maximum content width 1200px.

Mobile:
- no horizontal overflow
- single-column content
- stacked pricing cards
- vertical maintenance process
- vertical WooCommerce process
- accessible mobile navigation

## Accessibility

Use semantic HTML, proper heading hierarchy, keyboard-accessible controls, visible focus states, and sufficient contrast.

## Code quality

Use reusable Blade components and avoid duplicated markup.

Suggested components:

- header
- footer
- button
- status-badge
- service-card
- pricing-card
- metric-card
- dashboard-preview
- process-step
- activity-row
- faq-item

## Acceptance criteria

Before finishing:

1. Read all supplied brand files.
2. Verify logo usage against the supplied SVG assets.
3. Verify all colors against the brand palette.
4. Verify typography.
5. Check desktop and mobile layouts.
6. Check for horizontal overflow.
7. Check pricing toggle and tabs.
8. Check FAQ accordion.
9. Check mobile navigation.
10. Check that the result does not drift into generic AI/SaaS visual style.

Do not replace the approved brand direction with a new one without explicit instruction.
