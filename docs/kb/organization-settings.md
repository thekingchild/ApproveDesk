# Branding and public access

Open **Settings → Branding** to set the organization identity, choose the site's first page, and customize the public login screen. Use this when the product name, artwork, sign-in appearance, or self-registration policy changes.

## Application identity

The **Application identity** card affects signed-in pages, Login, and printable approval records.

- **Company name** is shown in the browser tab, navigation, Login, and approval reports. Leave it blank to use ApproveDesk.
- **Meta description** is optional text for search engines and link previews.
- **Application logo** accepts PNG, JPG, or WebP up to 1 MB. It appears in navigation, Login, and approval reports.
- **Favicon** accepts ICO, PNG, JPG, or WebP up to 512 KB.
- **Show public landing page** is off by default. Off sends guests to Login and signed-in people to Dashboard. On shows the installed public marketing page. The switch does not edit that page.

The upload cards preview a current or newly selected image. Use **Keep current** if you selected a replacement by mistake. Removing an image restores the product default after save. Identity artwork is public; do not upload confidential images.

## Self-registration

**Allow self-registration** controls whether a visitor can create an account. When it is on:

- **After someone registers** can require a 6-digit email confirmation (recommended) or grant immediate access. Confirmation codes expire after 10 minutes.
- **Registration domains** accepts one domain per line, such as `bluechipanalytical.com`. Leave it empty to accept any email provider. Subdomains match only when listed.

Administrator-created and imported users do not use the self-registration domain list. New accounts created with Google or Microsoft must still match the list; existing accounts can link those sign-in methods. The list is an onboarding control, not proof of identity; passwords, two-factor authentication, and passkeys remain the account safeguards.

## Login page

The **Login page** card controls the public sign-in appearance:

- Tagline, accent colour, background image, and overlay darkness.
- Optional CSS under **Advanced: custom CSS** for fine-grained visual adjustments.
- A live preview for the Login and Browser surfaces.

The background is shown in the large-screen side panel and behind the form on smaller screens. Check that text remains readable before saving.

## What not to do

- Do not upload confidential artwork.
- Do not put passwords, tokens, or private keys in CSS.
- Do not expect the public-landing switch to edit marketing content.
- Do not use the registration domain list as the only account-security control.
