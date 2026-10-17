# Public website (`website/`)

This folder **is** the optional marketing site. When an administrator turns on **Show public landing page** under **Settings → Branding**, Laravel serves it at `/` and injects the Log in / Sign up / Dashboard buttons. When the option is off (the default), `/` opens Login for guests and Dashboard for signed-in people, and files in this folder are not public. Replace these files with any static site built outside this repository.

## Availability

The setting controls the whole folder: `index.html`, additional pages, CSS, scripts, images, and other static files are served only while **Show public landing page** is on. Turning it off does not delete or change these files, so the same site returns when the option is enabled again.

## Layout

```
website/
  README.md       # this contract — keep it when you can
  index.html      # required — GET /
  css/            # optional
  js/             # optional
  images/         # optional
```

Do not put landing-page files in `public/`, `resources/views/`, or the Vite tree. No Blade, PHP, Livewire, or Flux here.

## Auth hook

`index.html` (and any other HTML that should show account buttons) must include exactly one empty element in its intended account-action area. The shipped v2.8.3 landing page places it in the hero action column:

```html
<nav data-approvedesk-auth></nav>
```

Laravel fills it on every HTML response:

- Guest, registration open: **Log in** + **Sign up**
- Guest, registration closed: **Log in** only
- Signed in: **Dashboard** (goes to `/dashboard`)

Style it from this site’s CSS:

```css
[data-approvedesk-auth] { }
[data-auth="login"],
[data-auth="dashboard"] { }
[data-auth="register"] { }
```

If the hook is missing, Laravel inserts a fallback bar after `<body>`. Still put the hook in your header.

## How to replace the site

1. Build a static site anywhere (hand-written HTML, a static generator, a designer export).
2. Put `<nav data-approvedesk-auth></nav>` in the header of `index.html`.
3. Use relative asset URLs (`css/app.css`, `images/hero.webp`).
4. Do not use reserved app paths (below).
5. Delete the current files in this folder (keep this README if you want the contract).
6. Copy the new files here.
7. Reload `/`. No PHP change, no `npm run build`, no migrate.

## Reserved paths

Never claim these as website URLs: `/login`, `/register`, `/dashboard`, `/admin`, `/settings`, `/submissions`, `/approvals`, `/analytics`, `/help`, `/notifications`, `/api`, `/install`, `/livewire`, `/build`, `/storage`, `/sanctum`, `/up`, `/branding`, `/manifest.webmanifest`, `/integrations`.

## What Laravel will not serve from here

Path traversal (`..`), dotfiles (`.env`, `.git`), PHP, and unknown extensions. Allowed: html, htm, css, js, map, svg, png, jpg, jpeg, gif, webp, ico, woff, woff2, ttf, eot, txt, xml, json, webmanifest, pdf, mp4, webm.

## Scripts and the Content Security Policy

ApproveDesk sends a Content Security Policy with every page. Inline `<script>` blocks in these files receive the page's nonce automatically when they are served, and scripts in `js/` load as usual. Inline event attributes (`onclick="..."`, `onload="..."`) are blocked by the browser; attach listeners from a script instead.
