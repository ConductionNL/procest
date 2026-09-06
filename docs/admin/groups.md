---
id: groups
title: Groups Dossiq expects
sidebar_position: 5
description: The Nextcloud groups the shipped case flows assign work to, why Dossiq creates them at install, and what to do when your user backend refuses that.
---

# Groups Dossiq expects

The shipped case flow assigns its behandelaar step to the Nextcloud group `behandelaars`. Dossiq creates that group at install and on every upgrade. The step is idempotent: an existing group is left alone, membership included.

## Who is in it at install

An empty group is no better than a missing one. The completion gate asks "is this user a member", and an empty group answers no for everybody. Measured on a fresh install: the group existed, the shipped journey still could not be walked.

So when Dossiq creates the group, it puts your administrators in it. That is the one membership an install can know, and it makes the shipped journey completable on day one.

Replace them with your real case handlers:

```bash
occ group:adduser behandelaars <user>
occ group:removeuser behandelaars admin
```

Dossiq touches membership only for a group it just created. A group you already manage is never changed.

## When the group is missing

Without the group, nobody can complete a step assigned to it. The completion signal is refused with "the user who completed the task is not the assignee of the awaiting step". That is deliberate: the gate fails closed rather than letting anyone answer.

Some user backends refuse group creation, LDAP-only setups for example. Dossiq then logs a warning during install. Create the group in your backend and the flow works without further changes.

## Which groups

| Group | Used by | Purpose |
|-------|---------|---------|
| `behandelaars` | shipped case flow, step `task-behandelaar` | case handlers who finish the inhoudelijke voorbereiding |

A test guards this table's code side: a shipped flow cannot assign work to a group the install does not provision.
