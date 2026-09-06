# Development

## Prerequisites

- Docker & Docker Compose
- Node.js >= 18
- npm
- A running Nextcloud instance with [OpenRegister](https://github.com/ConductionNL/openregister) installed

## Local Development

This app is developed using the [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev) environment. The app is volume-mounted into the Nextcloud container.

```bash
# Start the development environment
docker compose -f openregister/docker-compose.yml up -d

# Build the frontend
cd dossiq
npm install
npm run dev
```

The app will be available at `http://localhost:8080/apps/dossiq`.

## Frontend Build

```bash
npm install          # Install dependencies
npm run dev          # Development build (watch mode)
npm run build        # Production build
```

## Demo data

The dashboard widgets, the Tasks page and My Work all filter on the current
user, so a fresh instance shows empty panels even when the register imported
cleanly. Seed a working caseload:

```bash
occ dossiq:demo:seed              # 18 cases across the four case types, 32 tasks
occ dossiq:demo:seed --verify-only # report the buckets without creating anything
```

Safe to re-run. A case whose title is already present is skipped, and its tasks
with it.

The dates in `lib/Settings/demo_caseload_seed_data.json` are relative day
offsets, resolved when the seed runs, so the overdue and at-risk buckets stay
correct however long the file sits in the repo. Give a case either
`deadlineInDays` or `startInDays`, never both: `deadline` is a materialised
calculation over `startDate` plus the case type's `processingDeadline`, so the
seed reaches a deadline by backdating the start date.

`--verify-only` counts what the dashboard will read by querying the register
back, not by counting the seed file. A count taken from the input agrees with
the input whatever the materialiser did.

## Demo email

The Nextcloud dashboard's mail widget needs a mail server and an account. The
shared dev environment ships both.

```bash
docker compose -f .github/docker-compose.yml --profile mail up -d greenmail
bash .github/docker/mail/seed-mail.sh localhost 3025

occ app:enable mail
occ mail:account:create admin "Gemeente Demo" admin@test.local \
    conduction-greenmail 3143 none admin@test.local admin@test.local \
    conduction-greenmail 3025 none admin@test.local admin@test.local
occ mail:account:sync 1
```

Then put the widgets on the dashboard:

```bash
occ user:setting admin dashboard layout \
  "procest_my_tasks_widget,procest_task_reminders_widget,mail-unread,procest_overdue_cases_widget,procest_deadline_alerts_widget,procest_cases_overview_widget"
```

The widget ids still carry the `procest_` prefix. Renaming them would drop the
widget out of every layout that already names it, so they stay.

## Code Quality

```bash
composer phpcs       # PHP CodeSniffer — coding standards
composer cs:fix      # Auto-fix coding standard issues
composer phpmd       # PHP Mess Detector — complexity, naming, unused code
composer phpmetrics  # Generate HTML metrics report
```

## Product Page

The product page at [conduction.nl](https://conduction.nl) is built with [Docusaurus 3](https://docusaurus.io/) and deployed via GitHub Pages.

### How it works

- The Docusaurus setup lives in the `docusaurus/` folder
- Documentation content comes from the `docs/` folder at the project root — **not** duplicated inside `docusaurus/`
- The Docusaurus config uses `path: '../docs'` to reference the root docs directly
- Pushing to the `development` branch triggers the GitHub Actions workflow (`.github/workflows/documentation.yml`) which builds and deploys to the `gh-pages` branch
- GitHub Pages serves the built site at `procest.conduction.nl` (configured via `static/CNAME`)

### Local preview

```bash
cd docusaurus
npm install
npm start            # Dev server at http://localhost:3000 with hot reload
```

### Adding documentation

Simply add or edit Markdown files in the `docs/` folder. The sidebar is auto-generated from the folder structure. Changes will appear on the product page after pushing to `development`.
