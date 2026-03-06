# OpenSpec Setup for Procest

This project uses [OpenSpec](https://github.com/Fission-AI/OpenSpec) for change management (proposal → specs → design → tasks → review). The workflow schema is **shared** across all Nextcloud apps in the `apps-extra` folder.

## How the shared schema works

The OpenSpec schema files live in **one place**:

```
apps-extra/openspec/schemas/conduction/
├── schema.yaml
└── templates/
    ├── proposal.md, spec.md, design.md, review.md, tasks.md
```

Procest does **not** have its own copy. Instead, `procest/openspec/schemas` is a **junction** (Windows directory link) that points to the shared folder. Both paths access the **same files**:

- `apps-extra/openspec/schemas/conduction/schema.yaml`
- `procest/openspec/schemas/conduction/schema.yaml` ← same file via junction

Editing either path updates the same file. Changes apply to all apps in `apps-extra` that use this schema.

## After cloning: one-time setup

The `openspec/schemas` folder is in `.gitignore` (it's a link to shared files), so it is not committed. After cloning, you must create the junction.

### Prerequisites

- Procest must be inside the `nextcloud-docker-dev` workspace structure:
  ```
  nextcloud-docker-dev/
  └── workspace/server/apps-extra/
      ├── openspec/schemas/     ← shared schema (source of truth)
      └── procest/              ← this app
  ```

### Windows (PowerShell)

From the procest root:

```powershell
.\openspec\setup-schemas.ps1
```

Or manually:

```powershell
cd openspec
cmd /c mklink /J schemas "..\..\openspec\schemas"
```

### Linux / macOS

```bash
cd openspec
ln -s ../../openspec/schemas schemas
```

### Verify

After setup, both paths should show the same content:

```powershell
# Should show identical content
Get-Content openspec\schemas\conduction\schema.yaml | Select-Object -First 3
Get-Content ..\..\openspec\schemas\conduction\schema.yaml | Select-Object -First 3
```

## Using OpenSpec

With the schema linked, use the slash commands in your AI assistant:

- `/opsx:propose "add feature X"` — create a new change
- `/opsx:apply` — implement tasks
- `/opsx:verify` — run verification
- `/opsx:archive` — archive completed change

See [OpenSpec docs](https://github.com/Fission-AI/OpenSpec) for the full workflow.
