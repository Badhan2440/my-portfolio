# Portfolio — interactive features guide

Everything lives in `index.html`. The site is plain HTML/CSS/JS with GSAP for motion, so each
feature is a self-contained block you can find by searching for its marker comment.

```
portfolio-site/
├── index.html          ← the whole site
├── resume.pdf          ← opened by the Resume buttons
├── .nojekyll           ← tells GitHub Pages to serve files as-is
├── images/
│   ├── screens/        ← project screenshots (p2/, p3/, p4/ per project)
│   └── logos/          ← Ches's, IEEE PES, Memorial University
└── api/
    ├── connect.php     ← poll + ping wall backend (optional, any PHP 7.4+ host)
    └── data/.htaccess  ← keeps the data file private
```

## Where each feature lives

| Feature | CSS (search in `<style>`) | Markup (search in `<body>`) | JS (search in `<script>`) |
|---|---|---|---|
| Shared helpers (toast, modal, sound, neon) | `FEATURE STYLES` | `FEATURE: toast + terminal + game` | `SHARED HELPERS` |
| Terminal / CLI | `── terminal ──` | `id="term"`, `id="termBtn"` | `FEATURE: TERMINAL` |
| Tech playground | `── stack playground ──` | `FEATURE: STACK PLAYGROUND` | `FEATURE: STACK PLAYGROUND` + `SKILLS` data |
| Easter eggs + Bug Squash | `── bug squash` / `neon mode` | `id="game"` | `FEATURE: EASTER EGGS` |
| Poll + ping wall | `── connect wall ──` | `FEATURE: CONNECT WALL` | `FEATURE: CONNECT WALL` + `POLL` data |

Script order matters: **CONTENT → RENDER → SHARED HELPERS → INTRO → MOTION → FEATURES**.
Features only talk to the page through `window.PB` (`PB.toast`, `PB.go`, `PB.sfx`, `PB.openModal`, …).

## Using a feature in another codebase

1. Copy the `FEATURE STYLES` block (it relies on the `:root` tokens: `--bg`, `--fg`, `--dim`, `--line`, `--ice`, `--ice-rgb`, `--f-code`, `--f-blue`).
2. Copy the feature's markup.
3. Copy `SHARED HELPERS`, then the feature's JS block, plus its data (`SKILLS`/`TARGETS`, `POLL`/`CONNECT_API`, and `PROJECTS` for the terminal).
4. In React: put each JS block in a `useEffect(() => { … }, [])` inside a component that renders the markup; nothing else changes.

## Editing content

- **Terminal commands**: add an entry to `CMDS` — `name: { run(arg){ print(...) } }`. `help` lists commands by hand, so add a row there too.
- **Skills**: edit `SKILLS`. `uses` takes project ids (`p01`–`p04`) or keys from `TARGETS`. Group colours are in `SKILL_GROUPS`.
- **Poll**: edit `POLL`. If you use the PHP backend, keep `CHOICES` in `api/connect.php` matching the option ids.

## Poll + ping wall storage

The widget picks its storage automatically:

1. **claude.ai artifact** — shared live database (the hosted preview).
2. **Your host** — `api/connect.php`, when `CONNECT_API` points to it and the page is served over http(s).
3. **This browser only** — fallback when neither is available (e.g. opening the file by double-click). The widget says so.

To go live on your own host: upload the `api/` folder beside `index.html`. To moderate, set `ADMIN_TOKEN`
in `connect.php`, or edit/delete entries in `api/data/connect.json`.
Limits built in: one vote per visitor (changeable), one note a minute, five a day, 280 characters,
HTML stripped, a hidden honeypot field for bots.

## Shortcuts

- `` ` `` or Ctrl/⌘ + K — terminal
- ↑ ↑ ↓ ↓ ← → ← → B A — neon mode
- Click the name in the header three times — Bug Squash
- Sound toggle in the header (off by default, remembered per browser)

All motion respects *prefers-reduced-motion*: bubbles become a static grid, the game still works, the intro is skipped.

## Hosting on GitHub Pages

1. Upload the **contents** of `portfolio-site/` to the root of your repository (so `index.html` sits at the top level).
2. Settings → Pages → Source: *Deploy from a branch* → `main` / `(root)`.
3. Every path in the site is relative and lowercase (`images/...`, `resume.pdf`), so it works both at
   `username.github.io` and at `username.github.io/repo-name/`. GitHub Pages is case-sensitive: keep file names exactly as they are.
4. GitHub Pages cannot run PHP, so there the poll and ping wall save in each visitor's browser only
   (the widget says so). For a shared poll, host the whole folder on a PHP host such as cPanel instead.
