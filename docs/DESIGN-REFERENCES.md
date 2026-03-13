# Procest — Design References & Dashboard Wireframes

## 1. Design Inspiration Sources

### Dashboard / Landing Page
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Dribbble | Search "case management dashboard" | Case count widgets, status distribution, deadline tracking |
| Pinterest | Search "case management UX" | 30+ pins with government/legal dashboards |
| Dribbble | Search "task management dashboard" | Task boards, progress tracking, workload views |
| ServiceNow | servicenow.com | Enterprise case dashboard with status funnels, SLA meters |
| Jira Service Mgmt | atlassian.com/jira/service-management | Queue views, SLA tracking, workload distribution |

### Case Workflow / Timeline
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Dribbble | Search "case management workflow" | Status progression timelines, step-by-step flows |
| Pinterest | Search "workflow UI design" | Linear and branching workflow visualizations |
| Government portals | MijnOverheid pattern | Status timeline with icons, document checklist per status |
| Flowable | flowable.com/platform | CMMN case visualization with milestones |

### Task Kanban / Board
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Nextcloud Deck | apps.nextcloud.com/apps/deck | Board/Stack/Card — native Nextcloud kanban UX |
| Trello | trello.com | Minimal cards, clear columns, smooth drag-and-drop |
| Dribbble | Search "task board UI" | Task cards with assignee, due date, priority, case reference |
| Asana | asana.com | Task boards with multiple views (list, board, timeline) |

### My Work / Workload
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Jira "My Work" | atlassian.com/jira | Assigned issues with priority sorting, project labels |
| Asana "My Tasks" | asana.com | Today/upcoming/later sections, personal task view |
| Monday.com | monday.com | Personal workload with status colors, deadline indicators |

---

## 2. Missing Features Identified from Design Patterns

Features not currently in FEATURES.md but commonly present in case management dashboards and workflows:

### MVP Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Status timeline visualization on case detail | MijnOverheid, ServiceNow | Visual progress indicator showing which statuses have been passed |
| Case deadline countdown (days remaining) | All case management tools | At-a-glance urgency — "14 days remaining" or "3 days overdue" |
| Quick status change from list view | ServiceNow, Jira | Change case status without opening the detail page |

### V1 Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Average processing time KPI | ServiceNow, Valtimo | Dashboard metric: average days from start to completion per case type |
| Case type breakdown chart | Government dashboards | Pie/bar chart showing distribution of open cases by type |
| Document completion checklist | MijnOverheid, ZAC | Case detail shows which required documents are present vs missing |
| Property completion indicator | Government portals | Shows percentage of required custom fields filled per case |
| Days in current status | ServiceNow, Jira | Indicator on case showing how long it has been in current phase |
| Case urgency score | ServiceNow | Computed score combining priority + deadline proximity |
| Status transition history timeline | Flowable, Valtimo | Visual timeline of all status changes with timestamps and users |

### Enterprise Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| SLA compliance meter | ServiceNow, Jira | Percentage of cases meeting processing deadline |
| Case type performance comparison | Management dashboards | Compare avg processing time, completion rates across types |
| Handler workload heatmap | Jira, Monday.com | Visualize case distribution across handlers |
| Deadline forecast / risk analysis | Enterprise case tools | Predict which cases are likely to miss deadline based on current pace |

---

## 3. Dashboard Wireframes

### 3.1 Main Dashboard (Landing Page)

```
┌─────────────────────────────────────────────────────────────────────┐
│  PROCEST                                           [Search...] [+] │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│  Cases   │  Tasks   │ Decisions│  My Work │   Settings   │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐  │
│  │ OPEN CASES  │ │  OVERDUE    │ │  COMPLETED  │ │   MY TASKS  │  │
│  │             │ │             │ │  THIS MONTH │ │             │  │
│  │     24      │ │      3      │ │     12      │ │      7      │  │
│  │  +3 today   │ │  ⚠ action!  │ │  avg 18 days│ │  2 due today│  │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘  │
│                                                                     │
│  ┌────────────────────────────────┐ ┌──────────────────────────┐   │
│  │ Cases by Status                │ │ Overdue Cases            │   │
│  │                                │ │                          │   │
│  │  ██████████████████████  8     │ │ 🔴 Case #2024-042       │   │
│  │  Ontvangen                     │ │   Omgevingsvergunning    │   │
│  │                                │ │   5 days overdue         │   │
│  │  ████████████████        6     │ │   Handler: Jan           │   │
│  │  In behandeling                │ │                          │   │
│  │                                │ │ 🔴 Case #2024-038       │   │
│  │  ████████████            5     │ │   Subsidieaanvraag       │   │
│  │  Besluitvorming                │ │   2 days overdue         │   │
│  │                                │ │   Handler: Maria         │   │
│  │  ██████████              3     │ │                          │   │
│  │  Bezwaar                       │ │ 🟡 Case #2024-045       │   │
│  │                                │ │   Klacht behandeling     │   │
│  │  ████                    2     │ │   Due tomorrow           │   │
│  │  Afgehandeld (today)           │ │   Handler: Pieter        │   │
│  └────────────────────────────────┘ │                          │   │
│                                      │ [View all overdue →]    │   │
│  ┌────────────────────────────────┐ └──────────────────────────┘   │
│  │ Cases by Type                  │                                │
│  │                                │ ┌──────────────────────────┐   │
│  │ Omgevingsvergunning  ████  10  │ │ Recent Activity          │   │
│  │ Subsidieaanvraag     ███    7  │ │                          │   │
│  │ Klacht               ██     4  │ │ • Case #042 status →     │   │
│  │ Melding              ██     3  │ │   "In behandeling"       │   │
│  └────────────────────────────────┘ │   by Jan · 10 min ago    │   │
│                                      │                          │   │
│  ┌────────────────────────────────┐ │ • Decision recorded on   │   │
│  │ My Work (Top 5)               │ │   Case #036 "Vergunning  │   │
│  │                                │ │   verleend" — Maria      │   │
│  │ 🔴 Case #042 · Overdue 5d    │ │   1 hour ago             │   │
│  │    Omgevingsvergunning         │ │                          │   │
│  │                                │ │ • Task "Review docs"     │   │
│  │ ⚡ Task: Review documents     │ │   completed by Pieter    │   │
│  │    Case #045 · Due today       │ │   2 hours ago            │   │
│  │                                │ │                          │   │
│  │ ⚡ Case #048 · Due in 3 days  │ │ [View all activity →]    │   │
│  │    Subsidieaanvraag            │ └──────────────────────────┘   │
│  │                                │                                │
│  │ [View all my work →]          │                                │
│  └────────────────────────────────┘                                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.2 Case List View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PROCEST > Cases                               [+ New Case] [Filter]│
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│  Cases   │  Tasks   │ Decisions│  My Work │   Settings   │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│ Type: [All ▾]  Status: [Open ▾]  Handler: [All ▾]  [Search...]     │
│                                                                     │
│ ┌───┬──────────┬─────────────┬────────────┬─────────┬──────┬──────┐│
│ │   │ ID       │ Title       │ Type       │ Status  │Deadl.│Handl.││
│ ├───┼──────────┼─────────────┼────────────┼─────────┼──────┼──────┤│
│ │🔴│ 2024-042 │ Bouwverg.   │ Omgevings- │In behan.│Feb 20│ Jan  ││
│ │   │          │ Keizersgr.  │ vergunning │         │5d ovr│      ││
│ │🔴│ 2024-038 │ Subsidie    │ Subsidie-  │Besluit- │Feb 23│ Maria││
│ │   │          │ innovatie   │ aanvraag   │vorming  │2d ovr│      ││
│ │🟡│ 2024-045 │ Klacht over │ Klacht     │Ontvangen│Feb 26│Pieter││
│ │   │          │ buurman     │            │         │1 day │      ││
│ │  │ 2024-048 │ Subsidie    │ Subsidie-  │In behan.│Feb 28│ Jan  ││
│ │   │          │ verduurz.   │ aanvraag   │         │3 days│      ││
│ │  │ 2024-050 │ Bouwverg.   │ Omgevings- │Ontvangen│Mar 15│ —    ││
│ │   │          │ Prinsengr.  │ vergunning │         │18 day│      ││
│ │  │ 2024-051 │ Melding     │ Melding    │Ontvangen│Mar 20│ Maria││
│ │   │          │ wegdek      │            │         │23 day│      ││
│ └───┴──────────┴─────────────┴────────────┴─────────┴──────┴──────┘│
│                                                                     │
│  Showing 6 of 24 open cases · Page 1 of 4    [< 1 2 3 4 >]        │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.3 Case Detail View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PROCEST > Cases > #2024-042 Bouwvergunning Keizersgracht  [···]   │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  STATUS TIMELINE                                                    │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│  ● Ontvangen    ● In behandeling    ○ Besluitvorming   ○ Afgehandeld│
│    Jan 15          Feb 1              (current)                     │
│                                                                     │
│  ┌──────────────────────────────┐ ┌──────────────────────────────┐ │
│  │ CASE INFO                    │ │ DEADLINE & TIMING            │ │
│  │                              │ │                              │ │
│  │ Title:    Bouwvergunning     │ │ Started:   Jan 15, 2026      │ │
│  │           Keizersgracht 100  │ │ Deadline:  Feb 20, 2026      │ │
│  │ Type:     Omgevingsvergunning│ │                              │ │
│  │ Priority: High               │ │ 🔴 5 DAYS OVERDUE           │ │
│  │ Confid.:  Internal           │ │                              │ │
│  │                              │ │ Processing deadline: 56 days │ │
│  │ ID:       2024-042           │ │ Days elapsed: 41             │ │
│  │ Created:  Jan 15, 2026       │ │ Extension: allowed (+28d)    │ │
│  │                              │ │ [Request Extension]          │ │
│  │ [Change Status ▾]            │ └──────────────────────────────┘ │
│  └──────────────────────────────┘                                  │
│                                                                     │
│  ┌──────────────────────────────┐ ┌──────────────────────────────┐ │
│  │ PARTICIPANTS                 │ │ CUSTOM PROPERTIES            │ │
│  │                              │ │                              │ │
│  │ Handler:                     │ │ Kadastraal nr: AMS04-A-1234  │ │
│  │ 👤 Jan de Vries              │ │ Bouwkosten:    €250,000      │ │
│  │    [Reassign]                │ │ Oppervlakte:   180 m²        │ │
│  │                              │ │ Bouwlagen:     3             │ │
│  │ Initiator:                   │ │                              │ │
│  │ 👤 Petra Jansen (Acme Corp)  │ │ [Edit Properties]            │ │
│  │                              │ └──────────────────────────────┘ │
│  │ Advisor:                     │                                  │
│  │ 👤 Dr. K. Bakker             │                                  │
│  │                              │                                  │
│  │ [+ Add Participant]          │                                  │
│  └──────────────────────────────┘                                  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ REQUIRED DOCUMENTS                              3/5 complete │  │
│  │                                                              │  │
│  │ ✅ Bouwtekening (incoming)           uploaded Jan 16         │  │
│  │ ✅ Constructieberekening (incoming)  uploaded Jan 20         │  │
│  │ ✅ Situatietekening (incoming)       uploaded Jan 22         │  │
│  │ ❌ Welstandsadvies (internal)        required at: Besluit.  │  │
│  │ ❌ Vergunningsbesluit (outgoing)     required at: Afgehand. │  │
│  │                                                              │  │
│  │ [Upload Document]                                            │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌───────────────────┐ ┌────────────────────────────────────────┐  │
│  │ TASKS        3/5  │ │ DECISIONS                              │  │
│  │                   │ │                                        │  │
│  │ ✅ Intake check  │ │ (no decisions yet)                     │  │
│  │ ✅ Site visit    │ │                                        │  │
│  │ 🔄 Review docs  │ │ [+ Add Decision]                       │  │
│  │    Due: Feb 26   │ │                                        │  │
│  │    👤 Jan        │ └────────────────────────────────────────┘  │
│  │ ○ Draft decision │                                              │
│  │ ○ Send result    │                                              │
│  │                   │                                              │
│  │ [+ Add Task]      │                                              │
│  └───────────────────┘                                              │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ ACTIVITY TIMELINE                                [+ Add note]│  │
│  │                                                              │  │
│  │ Feb 25 · Task "Review docs" assigned to Jan de Vries         │  │
│  │                                                              │  │
│  │ Feb 20 · ⚠ DEADLINE PASSED — case is now overdue            │  │
│  │                                                              │  │
│  │ Feb 1  · Status changed to "In behandeling"                  │  │
│  │           by Jan de Vries                                    │  │
│  │                                                              │  │
│  │ Jan 22 · Document uploaded: "Situatietekening"               │  │
│  │           by Petra Jansen (via portal)                       │  │
│  │                                                              │  │
│  │ Jan 20 · Document uploaded: "Constructieberekening"          │  │
│  │                                                              │  │
│  │ Jan 16 · Document uploaded: "Bouwtekening"                   │  │
│  │                                                              │  │
│  │ Jan 15 · Case created from request #REQ-2024-089             │  │
│  │           Type: Omgevingsvergunning                          │  │
│  │           Handler: Jan de Vries                              │  │
│  │           Deadline: Feb 20, 2026 (56 days)                   │  │
│  │                                                              │  │
│  │ [Load more...]                                               │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ SUB-CASES                                                    │  │
│  │                                                              │  │
│  │ (no sub-cases)                                               │  │
│  │ [+ Create Sub-case]                                          │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.4 Task Board (Kanban)

```
┌─────────────────────────────────────────────────────────────────────┐
│  PROCEST > Tasks                       [Board | List]  [+ New Task] │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│  Cases   │  Tasks   │ Decisions│  My Work │   Settings   │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│ Case: [All Cases ▾]  Assignee: [All ▾]  Priority: [All ▾]          │
│                                                                     │
│ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌────────────┐ │
│ │ AVAILABLE    │ │ ACTIVE       │ │ COMPLETED    │ │ TERMINATED │ │
│ │ 4 tasks      │ │ 6 tasks      │ │ 12 tasks     │ │ 1 task     │ │
│ │──────────────│ │──────────────│ │──────────────│ │────────────│ │
│ │┌────────────┐│ │┌────────────┐│ │┌────────────┐│ │┌──────────┐│ │
│ ││Review docs ││ ││Draft reply ││ ││Intake check││ ││Site visit││ │
│ ││Case #042   ││ ││Case #038   ││ ││Case #042   ││ ││Case #039 ││ │
│ ││📅 Feb 26   ││ ││📅 Feb 27   ││ ││✅ Jan 20   ││ ││❌ Feb 15 ││ │
│ ││👤 Jan      ││ ││👤 Maria    ││ ││👤 Jan      ││ ││👤 Pieter ││ │
│ ││⚡ high     ││ ││⚡ urgent   ││ │└────────────┘│ │└──────────┘│ │
│ │└────────────┘│ │└────────────┘│ │┌────────────┐│ │            │ │
│ │┌────────────┐│ │┌────────────┐│ ││Site visit  ││ │            │ │
│ ││Collect info││ ││Assess claim││ ││Case #042   ││ │            │ │
│ ││Case #048   ││ ││Case #045   ││ ││✅ Jan 25   ││ │            │ │
│ ││📅 Mar 1    ││ ││📅 Feb 26   ││ ││👤 Jan      ││ │            │ │
│ ││👤 Jan      ││ ││👤 Pieter   ││ │└────────────┘│ │            │ │
│ │└────────────┘│ │└────────────┘│ │┌────────────┐│ │            │ │
│ │┌────────────┐│ │┌────────────┐│ ││Review      ││ │            │ │
│ ││Contact     ││ ││Prepare     ││ ││budget      ││ │            │ │
│ ││applicant   ││ ││decision    ││ ││Case #038   ││ │            │ │
│ ││Case #050   ││ ││Case #042   ││ ││✅ Feb 10   ││ │            │ │
│ ││📅 Mar 3    ││ ││📅 Mar 5    ││ ││👤 Maria    ││ │            │ │
│ ││👤 Maria    ││ ││👤 Jan      ││ │└────────────┘│ │            │ │
│ │└────────────┘│ │└────────────┘│ │              │ │            │ │
│ │┌────────────┐│ │┌────────────┐│ │ [+3 more]   │ │            │ │
│ ││Check regs  ││ ││Write report││ │              │ │            │ │
│ ││Case #051   ││ ││Case #048   ││ │              │ │            │ │
│ ││📅 Mar 10   ││ ││📅 Mar 1    ││ │              │ │            │ │
│ ││👤 —        ││ ││👤 Jan      ││ │              │ │            │ │
│ │└────────────┘│ │└────────────┘│ │              │ │            │ │
│ │              │ │┌────────────┐│ │              │ │            │ │
│ │              │ ││Schedule    ││ │              │ │            │ │
│ │              │ ││hearing     ││ │              │ │            │ │
│ │              │ ││Case #045   ││ │              │ │            │ │
│ │              │ ││📅 Mar 2    ││ │              │ │            │ │
│ │              │ ││👤 Pieter   ││ │              │ │            │ │
│ │              │ │└────────────┘│ │              │ │            │ │
│ │ [+ Add]      │ │ [+ Add]      │ │              │ │            │ │
│ └──────────────┘ └──────────────┘ └──────────────┘ └────────────┘ │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**Task card anatomy:**
```
┌──────────────────┐
│ Task Title       │  ← Title (clickable → task detail or case)
│ Case #042        │  ← Parent case reference (clickable)
│ 📅 Feb 26        │  ← Due date (red if overdue)
│ 👤 Jan           │  ← Assignee avatar + name
│ ⚡ high          │  ← Priority badge (if not normal)
│ 🔴 1 day overdue │  ← Overdue warning (if applicable)
└──────────────────┘
```

### 3.5 My Work View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PROCEST > My Work                               [Filter ▾] [Sort] │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│  Cases   │  Tasks   │ Decisions│  My Work │   Settings   │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│  Showing: [All ▾]  Cases (3) · Tasks (4)              7 items total │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ 🔴 OVERDUE                                                   │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [CASE] #2024-042 Bouwvergunning      ⚡ HIGH          │  │  │
│  │ │ Type: Omgevingsvergunning · Status: In behandeling     │  │  │
│  │ │ Deadline: Feb 20, 2026               🔴 5 days overdue │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [CASE] #2024-038 Subsidie innovatie                    │  │  │
│  │ │ Type: Subsidieaanvraag · Status: Besluitvorming        │  │  │
│  │ │ Deadline: Feb 23, 2026               🔴 2 days overdue │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ DUE THIS WEEK                                                │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [TASK] Review documents                  ⚡ HIGH       │  │  │
│  │ │ Case: #2024-042 Bouwvergunning Keizersgracht           │  │  │
│  │ │ Due: Feb 26, 2026  (1 day)                             │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [CASE] #2024-048 Subsidie verduurzaming   normal       │  │  │
│  │ │ Type: Subsidieaanvraag · Status: In behandeling        │  │  │
│  │ │ Deadline: Feb 28, 2026  (3 days)                       │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [TASK] Collect information                normal       │  │  │
│  │ │ Case: #2024-048 Subsidie verduurzaming                 │  │  │
│  │ │ Due: Mar 1, 2026  (4 days)                             │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ UPCOMING                                                     │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [TASK] Contact applicant                 normal        │  │  │
│  │ │ Case: #2024-050 Bouwvergunning Prinsengracht           │  │  │
│  │ │ Due: Mar 3, 2026                                       │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [TASK] Prepare decision                  normal        │  │  │
│  │ │ Case: #2024-042 Bouwvergunning Keizersgracht           │  │  │
│  │ │ Due: Mar 5, 2026                                       │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.6 Admin Settings — Case Type Management

```
┌─────────────────────────────────────────────────────────────────────┐
│  Administration > Procest                                           │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ CASE TYPES                                 [+ Add Case Type] │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ ★ Omgevingsvergunning (default)           Published   │  │  │
│  │ │   Deadline: 56 days · 4 statuses · 3 result types     │  │  │
│  │ │   Valid: Jan 2026 – Dec 2027                           │  │  │
│  │ │                                              [Edit ▸]  │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │   Subsidieaanvraag                        Published   │  │  │
│  │ │   Deadline: 42 days · 3 statuses · 2 result types     │  │  │
│  │ │   Valid: Jan 2026 – (no end)                           │  │  │
│  │ │                                              [Edit ▸]  │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │   Klacht behandeling                      Published   │  │  │
│  │ │   Deadline: 28 days · 3 statuses · 2 result types     │  │  │
│  │ │   Valid: Jan 2026 – (no end)                           │  │  │
│  │ │                                              [Edit ▸]  │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │   Bezwaarschrift                    ⚠ DRAFT           │  │  │
│  │ │   Deadline: 84 days · 2 statuses (incomplete)          │  │  │
│  │ │   Valid: (not set)                                     │  │  │
│  │ │                                              [Edit ▸]  │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.7 Admin Settings — Case Type Detail (Edit Mode)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Administration > Procest > Omgevingsvergunning            [Save]   │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────┬──────────┬──────────┬──────────┬──────────┬────────┐  │
│  │ General │ Statuses │ Results  │  Roles   │Properties│  Docs  │  │
│  ├─────────┴──────────┴──────────┴──────────┴──────────┴────────┤  │
│  │                                                              │  │
│  │ GENERAL                                                      │  │
│  │                                                              │  │
│  │ Title:              [Omgevingsvergunning              ]      │  │
│  │ Description:        [Vergunning voor bouwactiviteiten ]      │  │
│  │ Purpose:            [Beoordelen bouwplannen           ]      │  │
│  │ Trigger:            [Aanvraag van burger/bedrijf      ]      │  │
│  │ Subject:            [Bouw- en verbouwactiviteiten     ]      │  │
│  │                                                              │  │
│  │ Processing deadline: [56] days (ISO: P56D)                   │  │
│  │ Service target:      [42] days (ISO: P42D)                   │  │
│  │ Extension allowed:   [✓] Period: [28] days (ISO: P28D)       │  │
│  │ Suspension allowed:  [✓]                                     │  │
│  │                                                              │  │
│  │ Origin:             (●) External  ( ) Internal               │  │
│  │ Confidentiality:    [Internal           ▾]                   │  │
│  │ Publication req.:   [✓] Text: [Bouwvergunning verleend...]   │  │
│  │                                                              │  │
│  │ Valid from:         [2026-01-01]                              │  │
│  │ Valid until:        [2027-12-31]                              │  │
│  │ Status:             (●) Published  ( ) Draft                 │  │
│  │                                                              │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ STATUSES (drag to reorder)                       [+ Add]    │  │
│  │                                                              │  │
│  │ ☰ 1. Ontvangen                              [ ] Final      │  │
│  │ ☰ 2. In behandeling            notify: [✓]  [ ] Final      │  │
│  │       Text: "Uw zaak is in behandeling genomen"              │  │
│  │ ☰ 3. Besluitvorming                         [ ] Final      │  │
│  │ ☰ 4. Afgehandeld               notify: [✓]  [✓] Final      │  │
│  │       Text: "Uw zaak is afgehandeld"                         │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ RESULT TYPES                                     [+ Add]    │  │
│  │                                                              │  │
│  │ • Vergunning verleend    Archive: retain   Retention: 20yr  │  │
│  │ • Vergunning geweigerd   Archive: destroy  Retention: 10yr  │  │
│  │ • Ingetrokken            Archive: destroy  Retention: 5yr   │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ ROLE TYPES                                       [+ Add]    │  │
│  │                                                              │  │
│  │ • Aanvrager            Generic: initiator                    │  │
│  │ • Behandelaar          Generic: handler                      │  │
│  │ • Technisch adviseur   Generic: advisor                      │  │
│  │ • Beslisser            Generic: decision_maker               │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ PROPERTY DEFINITIONS                             [+ Add]    │  │
│  │                                                              │  │
│  │ • Kadastraal nummer     text    max: 20    req @ In behand. │  │
│  │ • Bouwkosten            number              req @ Besluit.  │  │
│  │ • Oppervlakte           number              optional        │  │
│  │ • Bouwlagen             number              optional        │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ DOCUMENT TYPES                                   [+ Add]    │  │
│  │                                                              │  │
│  │ • Bouwtekening          incoming   req @ In behandeling      │  │
│  │ • Constructieberekning  incoming   req @ In behandeling      │  │
│  │ • Situatietekening      incoming   req @ In behandeling      │  │
│  │ • Welstandsadvies       internal   req @ Besluitvorming      │  │
│  │ • Vergunningsbesluit    outgoing   req @ Afgehandeld         │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```
