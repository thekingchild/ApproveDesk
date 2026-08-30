# Application themes

An **application theme** is a fixed, accessible color palette used on signed-in ApproveDesk pages. It changes accents, links, focus indicators, and related application colors. It does not replace the logo, favicon, login background, marketing website, or owner-controlled Branding custom CSS.

The **organization default theme** is the palette used when a person has not selected another available theme. Every person can choose an enabled palette under **Settings → Appearance** and can separately choose Light, Dark, or System mode.

## Choose your theme

1. Open **Settings → Appearance**.
2. Under **Light and dark mode**, choose Light, Dark, or System. System follows your device.
3. Under **Application theme**, choose **Organization default** or one of the palettes enabled by the System owner.
4. Select **Save application theme**.
5. Open or reload a signed-in page to apply the saved palette.

If the System owner later disables or archives your selected palette, ApproveDesk safely uses the current organization default. Your Light, Dark, or System choice remains independent.

## Manage the theme catalogue

Only the System owner can open **Administration → Application themes**.

The catalogue includes built-in palettes and custom palettes:

- A built-in palette has a permanent stable key and permanent color values. It can be enabled or disabled, but not edited or archived.
- A custom palette has a name, a stable key, and separate primary/foreground color pairs for light and dark mode.
- **Enabled** themes appear as choices under Settings → Appearance.
- **Default** is the enabled fallback for the organization. Exactly one theme is the default.

### Create a custom theme

1. Enter a clear name and a lowercase stable key using letters, numbers, and single hyphens.
2. Enter each color as `#` followed by exactly six hexadecimal digits.
3. Provide a primary and foreground color for Light mode and another pair for Dark mode.
4. Confirm the previews remain readable. ApproveDesk requires at least a 4.5:1 WCAG contrast ratio for both pairs.
5. Select **Save custom theme**.

A new custom theme is enabled but does not become the organization default automatically. Use **Make default** when it should be the fallback for everyone.

## Enable, disable, and archive

- **Enable** makes a palette selectable.
- **Disable** removes it from new selections. The organization default cannot be disabled, and at least one theme must remain enabled.
- **Make default** selects an enabled, unarchived palette as the organization fallback.
- **Archive** removes a custom palette from use while retaining its record. Choose another default first if necessary. Built-in palettes cannot be archived.

## What not to do

- Do not paste CSS, HTML, gradients, or scripts into theme color fields. Themes accept only fixed color tokens.
- Do not use a custom theme to change login or public-site branding; use **Settings → Branding** for those surfaces.
- Do not disable the default before selecting another enabled default.
- Do not reuse a stable key for another palette.
- Do not rely on color alone to communicate approval status or urgency.
