---
id: flows
title: Adopt the flows Dossiq ships
sidebar_position: 6
description: Dossiq ships two OpenRegister flows. They arrive without an owner and switched off. Adopt them once, and the shipped case journey runs.
---

# Adopt the flows Dossiq ships

Dossiq ships two flows. `Case behandeling` walks a case from intake to closure. `Bezwaar advies` handles an advice request on an objection.

Both arrive **without an owner and switched off**. Nothing runs until you adopt them. That is by design, and it takes one command.

## Do this after installing

```bash
occ dossiq:flows:adopt --user <your-admin-uid> --enable
```

Check what it would do first:

```bash
occ dossiq:flows:adopt --user <your-admin-uid> --dry-run
```

The command is idempotent. Run it again after an upgrade and it reports what is already done.

## Why they do not arrive ready

OpenRegister owns the flow engine, and it decides how a shipped flow arrives. Its rule: importing a flow is not the same as agreeing to run it.

A flow runs as somebody. Every run resolves that person's credentials and permissions. Installing an app is not that person volunteering, so OpenRegister stores the flow with no owner and refuses to dispatch it.

Adopting a flow makes you its owner. From then on its runs execute as you, which is why nobody can do it on your behalf.

Three separate questions, three separate answers:

| Question | Answered by |
|----------|-------------|
| Which graph would run? | publishing, done at import |
| Whose identity does it run as? | adoption, this command |
| May it run at all? | enabling, the `--enable` flag |

## How to tell they are not adopted

Run `occ maintenance:repair`. It ends with a warning naming each flow that still needs you, and the command above. `occ upgrade` and the web updater print the same warning.

`occ app:enable dossiq` prints nothing, and that surprises people. Nextcloud does run the repair steps on that path. Only `maintenance:repair` and `upgrade` subscribe to what those steps report, so on the docker path the terminal stays quiet. The log still records it:

```
Dossiq: shipped flows await adoption {"pending":2,"total":2,"command":"occ dossiq:flows:adopt --user <admin> --enable"}
```

On a running instance the flow list shows the owner and the toggle. In the log, an unadopted flow leaves this line every time a case is created:

```
[FlowLocator] matched trigger "object.created" but was not dispatched: it has no owner
```

Nothing errors. Cases save, the app works, and the shipped journey never starts.

## When you want a different owner

Adoption is never a takeover. A flow already owned by somebody else is reported and left alone.

To move a flow to another user, ask that user to run the command themselves after the current owner releases it in the flow editor.

## Next

Add your case handlers to the groups the flows assign work to: [Groups Dossiq expects](./groups.md).
