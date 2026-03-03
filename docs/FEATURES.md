# Procest — Feature Analysis & Product Strategy

## Executive Summary

There is **no lightweight, self-hosted case management system that integrates with a collaboration platform**. The market has enterprise BPM suites that are heavyweight and expensive (Camunda, Flowable), Dutch government implementations that are API-only without user-facing UI (OpenZaak), and SaaS platforms with data sovereignty issues (ServiceNow, Monday.com). Procest fills all three gaps by being lightweight, Nextcloud-native, and government-ready.

**Key insight**: Case management is fundamentally about coordination — tracking work, assigning tasks, meeting deadlines, managing documents, and making decisions. Nextcloud already provides task management (Calendar/VTODO), file management, real-time chat, and activity feeds. A Nextcloud-native case manager orchestrates these capabilities rather than rebuilding them.

## 1. Competitive Landscape

### Nextcloud Ecosystem

| Name | Status | Approach |
|------|--------|----------|
| **Nextcloud Deck** | Bundled, active | Kanban board for tasks — not case management |
| **Nextcloud Tasks** | Available | CalDAV VTODO client — individual task tracking |
| **Nextcloud Forms** | Available | Form builder — intake only, no workflow |

**Finding**: No case management solution exists in Nextcloud. Deck and Tasks handle individual work items but lack case lifecycle, roles, decisions, and compliance features.

### Dutch Government (Zaakgericht Werken)

| Name | Positioning | Strengths | Weaknesses |
|------|------------|-----------|------------|
| **OpenZaak** | ZGW API reference implementation | Full ZGW compliance, production-proven (40+ municipalities) | API-only — no end-user UI, requires frontend |
| **Valtimo/GZAC** | Commercial case platform + ZGW | BPMN/DMN engine, document handling, ZGW connector | Proprietary core (Ritense), Java/Spring stack, heavy |
| **ZAC (Dimpact)** | Municipal frontend on OpenZaak | Full zaakgericht werken workflow | Tightly coupled to OpenZaak, limited outside NL |
| **Camunda ZGW** | BPMN engine + ZGW connectors | Powerful process automation | Complex setup, Java, enterprise pricing |
| **Rx.Mission** | Municipal case management | Document-centric, archival focus | Legacy, not open source |

### International Open Source

| Name | Positioning | Strengths | Weaknesses |
|------|------------|-----------|------------|
| **Camunda 8** | Process orchestration platform | BPMN/DMN engine, scalable, cloud-native | Enterprise pricing, complex, no case management UI |
| **Flowable** | BPM + CMMN engine | Full CMMN 1.1 support, lightweight embeddable | Java/Spring, no collaboration features |
| **Bonita** | Low-code BPM | Visual process designer, form builder | Proprietary features, no CMMN |
| **jBPM** | Red Hat BPM suite | Full BPMN/CMMN, rule engine (Drools) | Heavy Java stack, declining community |
| **ProcessMaker** | Low-code process automation | Drag-and-drop designer, API-first | Proprietary enterprise features |

### Enterprise SaaS

| Name | Price/user/mo | Strengths | Why Not |
|------|--------------|-----------|---------|
| **ServiceNow** | $100+ | Market leader, IT + business workflows | Extreme cost, vendor lock-in, SaaS only |
| **Monday.com** | $8-16 | Beautiful UX, flexible workflows | SaaS, no government compliance, no case model |
| **Jira Service Mgmt** | $17-47 | Developer-friendly, ITSM | Atlassian ecosystem lock-in, not case management |
| **Microsoft Power Automate** | $15+ | Microsoft integration, low-code | M365 dependency, data sovereignty concerns |
| **Kissflow** | $15+ | Simple workflow builder | SaaS only, limited case management |

## 2. Feature Matrix

### Case Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Case CRUD with lifecycle | **MVP** | Core entity |
| Case list with search, sort, filters | **MVP** | Navigation |
| Case detail view with timeline | **MVP** | Critical UX pattern |
| Status timeline visualization on case detail | **MVP** | Visual progress showing passed/current/future statuses |
| Case deadline countdown (days remaining / days overdue) | **MVP** | At-a-glance urgency indicator |
| Quick status change from case list view | **MVP** | Common pattern: change status without opening detail |
| Case type system (configurable) | **V1** | Flexible case definitions |
| Sub-cases (parent/child hierarchy) | **V1** | Complex case structures |
| Document completion checklist (case detail) | **V1** | Shows which required documents are present vs missing |
| Property completion indicator | **V1** | Percentage of required custom fields filled |
| Days in current status indicator | **V1** | Shows how long a case has been in current phase |
| Case templates | **V1** | Standardized case creation |
| Case cloning | **V1** | Efficiency for similar cases |
| Configurable status workflows per type | **Enterprise** | Organization-specific lifecycles |
| CMMN runtime (sentries, entry/exit criteria) | **Enterprise** | Advanced case automation |
| Bulk case operations | **Enterprise** | Scale operations |

### Task Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Task CRUD linked to cases | **MVP** | Core work tracking |
| Task list with status filters | **MVP** | Workflow overview |
| Task assignment to users | **MVP** | Workload distribution |
| Task due dates and priorities | **MVP** | Time management |
| Task checklist (sub-items) | **V1** | Detailed work breakdown |
| Task dependencies (blocked by) | **V1** | Sequencing work |
| Kanban board view for tasks | **V1** | Visual task management |
| Task templates per case type | **V1** | Standardized workflows |
| Automated task creation on status change | **Enterprise** | Workflow automation |
| Workload dashboard (tasks per user) | **Enterprise** | Management visibility |

### Status & Lifecycle

| Feature | Tier | Justification |
|---------|------|---------------|
| Status tracking (current phase) | **MVP** | Core lifecycle |
| Status history (audit trail) | **MVP** | Accountability |
| Configurable status types | **V1** | Organization-specific phases |
| Status change notifications | **V1** | Immediate feedback |
| Status-based access control | **Enterprise** | Phase-dependent permissions |
| SLA tracking (time in status) | **Enterprise** | Service quality |

### Roles & Participants

| Feature | Tier | Justification |
|---------|------|---------------|
| Assign handler to case | **MVP** | Basic assignment |
| Role types (initiator, handler, advisor) | **MVP** | CMMN role model |
| Multiple participants per case | **V1** | Team collaboration |
| Role-based permissions per case | **V1** | Access control |
| Automatic role assignment rules | **Enterprise** | Scale operations |
| External participant support | **Enterprise** | Cross-organization cases |

### Results & Decisions

| Feature | Tier | Justification |
|---------|------|---------------|
| Case result recording | **MVP** | Case closure |
| Decision CRUD linked to cases | **V1** | Formal decision tracking |
| Decision with effective/expiry dates | **V1** | Legal validity periods |
| Result types (configurable) | **V1** | Classification |
| Decision templates | **Enterprise** | Standardized decisions |
| DMN decision tables | **Enterprise** | Automated decision logic |

### Case Type System

| Feature | Tier | Justification |
|---------|------|---------------|
| Case type CRUD (admin) | **MVP** | Core behavioral configuration |
| Case type controls allowed statuses | **MVP** | Status lifecycle per type |
| Case type controls processing deadline | **MVP** | Automatic deadline calculation |
| Case type draft/published lifecycle | **MVP** | Safe configuration changes |
| Case type validity periods (validFrom/validUntil) | **MVP** | Version management |
| Case type controls allowed roles | **V1** | Role restriction per type |
| Case type controls result types (with archival rules) | **V1** | Outcome classification |
| Case type custom property definitions | **V1** | Organization-specific fields |
| Case type required documents per status | **V1** | Compliance controls |
| Case type decision type definitions | **V1** | Decision classification |
| Case type confidentiality defaults | **V1** | Security defaults |
| Case type suspension/extension rules | **V1** | Deadline management |
| Case type sub-case type restrictions | **Enterprise** | Hierarchical control |
| Case type versioning chains | **Enterprise** | Auditable type evolution |
| Case type import/export | **Enterprise** | Share types across instances |

### My Work (Werkvoorraad)

| Feature | Tier | Justification |
|---------|------|---------------|
| Personal workload view (my cases, my tasks) | **MVP** | Productivity essential |
| Sort by priority and due date/deadline | **MVP** | Task prioritization |
| Filter by entity type (cases, tasks) | **MVP** | Focused views |
| Overdue item highlighting | **MVP** | Proactive management |
| Cross-app workload (include Pipelinq leads/requests) | **V1** | Unified work queue |
| Workload analytics (items per user) | **Enterprise** | Management visibility |

### Admin Settings

| Feature | Tier | Justification |
|---------|------|---------------|
| Nextcloud admin settings page | **MVP** | App configuration |
| Case type management UI | **MVP** | Core configuration |
| Status type management per case type | **MVP** | Lifecycle configuration |
| Default case type selection | **MVP** | Out-of-box experience |
| Result type management per case type | **V1** | Outcome configuration |
| Role type management per case type | **V1** | Role configuration |
| Property definition management | **V1** | Custom field configuration |
| Document type management | **V1** | Document requirement configuration |
| Decision type management | **V1** | Decision configuration |
| Confidentiality level visibility | **Enterprise** | Security customization |

### Communication & Collaboration

| Feature | Tier | Justification |
|---------|------|---------------|
| Internal notes on cases (ICommentsManager) | **MVP** | Collaboration basics |
| Shared case views (multi-user access) | **MVP** | Team case management |
| Talk integration (per-case chat, IBroker) | **V1** | Real-time discussion |
| Calendar integration (deadlines, IManager) | **V1** | Deadline visibility |
| Activity stream (case events, IManager) | **V1** | Unified timeline |
| Notifications (assignment, status, deadline) | **V1** | Immediate feedback |
| User mentions in notes | **V1** | Team collaboration |
| Email notifications on case updates | **V1** | External communication |
| Email templates per case type | **Enterprise** | Standardized correspondence |

### Document Management

| Feature | Tier | Justification |
|---------|------|---------------|
| File attachments on cases (IRootFolder) | **V1** | Document management |
| Shared folder per case (Files) | **V1** | Case dossier |
| Document categorization | **V1** | Classification |
| Document versioning (via Nextcloud) | **V1** | Audit trail |
| Document templates per case type | **Enterprise** | Standardized documents |
| Digital signature integration | **Enterprise** | Legal validity |

### Reporting & Analytics

| Feature | Tier | Justification |
|---------|------|---------------|
| Dashboard with case counts and status overview | **MVP** | At-a-glance visibility |
| Case status distribution chart | **MVP** | Visual overview |
| List/table export (CSV) | **V1** | Data portability |
| KPI dashboard (avg processing time, open cases) | **V1** | Management visibility |
| Case type breakdown chart | **V1** | Distribution of open cases by type |
| Average processing time per case type | **V1** | Performance metric per type |
| Overdue case alerts | **V1** | Proactive management |
| SLA compliance meter (% cases meeting deadline) | **Enterprise** | Service quality tracking |
| Case type performance comparison | **Enterprise** | Compare avg time, completion rates |
| Handler workload heatmap | **Enterprise** | Visualize case distribution across handlers |
| Custom report builder | **Enterprise** | Flexible analytics |
| Trend analysis (case volume over time) | **Enterprise** | Strategic planning |

### Security & Compliance

| Feature | Tier | Justification |
|---------|------|---------------|
| RBAC via OpenRegister | **MVP** | Access control |
| Full audit trail (who changed what, when) | **MVP** | Accountability |
| WCAG AA compliance | **MVP** | Government requirement |
| Confidentiality levels on cases | **V1** | Sensitive case handling |
| GDPR data export (right of access) | **V1** | EU compliance |
| GDPR data deletion (right to erasure) | **V1** | EU compliance |
| NL Design System theming | **V1** | Government visual compliance |
| Data retention policies | **Enterprise** | Compliance automation |
| Archival management (archiefwet) | **Enterprise** | Dutch archival law |
| Field-level access control | **Enterprise** | Sensitive data protection |

### Integration

| Feature | Tier | Justification |
|---------|------|---------------|
| Pipelinq bridge (request-to-case) | **V1** | CRM-to-case workflow |
| ZGW Zaken API mapping | **V1** | Dutch gov interop |
| ZGW Besluiten API mapping | **V1** | Dutch decision interop |
| ZGW Catalogi API mapping | **V1** | Dutch type catalog interop |
| External REST API | **V1** | OpenRegister provides this |
| Nextcloud Flows automation | **Enterprise** | Low-code triggers |
| Webhook support | **Enterprise** | External integration |
| Federated case sharing | **Enterprise** | Cross-organization cases |

### Customization

| Feature | Tier | Justification |
|---------|------|---------------|
| Configurable list columns | **V1** | UI flexibility |
| Custom fields per case type (OpenRegister schema) | **V1** | Organization-specific needs |
| Saved views/filters | **V1** | User productivity |
| Custom dashboards | **Enterprise** | Personalized views |
| Public intake form (citizen-facing) | **Enterprise** | External case submission |
| Workflow designer (visual) | **Enterprise** | Admin-configured automation |

## 3. Gap Analysis

### What Competitors Do Well

- **BPM engines (Camunda, Flowable)**: Mature BPMN/CMMN/DMN runtime, process automation, scalability
- **Dutch gov (OpenZaak, Valtimo)**: Full ZGW compliance, archival management, established in municipalities
- **Enterprise SaaS (ServiceNow)**: Polished UX, AI features, mobile apps, marketplace

### What They Lack

| Gap | Opportunity for Procest |
|-----|------------------------|
| No native collaboration platform | Chat, files, calendar, contacts are separate systems in all competitors |
| No federation/cross-org sharing | Only Procest can share case data across organizations via Nextcloud federation |
| Integration tax | Competitors need separate connectors for every tool; Procest gets them free |
| No CRM-to-case flow | No competitor has native request-to-case conversion with a built-in CRM |
| No NL Design System theming | No competitor supports Dutch government design tokens natively |
| Heavyweight deployment | BPM engines require Java/Spring stacks; Procest runs inside existing Nextcloud |
| Data locked in case silo | Procest data on OpenRegister is reusable by Pipelinq, OpenCatalogi, etc. |

### Nextcloud-Native Advantages

| Capability | Why Competitors Cannot Match It |
|------------|-------------------------------|
| Zero-cost collaboration stack | Would need 5+ separate tool integrations for chat, files, calendar |
| Federated cross-org cases | Requires federation protocol; no case system has this |
| CRM + Case in one platform | Pipelinq → Procest is a unique integrated pipeline |
| Design token theming | NL Design System via nldesign app is Nextcloud-specific |
| Data platform reuse | OpenRegister objects shared across apps |
| Air-gapped deployment | Enterprise platforms cannot function without internet |
| Talk rooms per case | Built-in real-time chat; no BPM engine has this |
| Calendar-native deadlines | Case deadlines appear in user's calendar without sync |
| ~40-50% infrastructure free | Tasks, files, notifications, activity, comments — already built |

## 4. Strategic Positioning

### Positioning Statement

**Procest is case management that lives where your team already works.** Built natively into Nextcloud, it turns your existing collaboration platform into a case management system — with files, calendar, chat, and activity already connected.

### Differentiation Strategy

Three pillars:

1. **Platform leverage** — Every Nextcloud feature (AI, workflows, federation, files) automatically benefits Procest
2. **Government-first** — ZGW standard alignment, NL Design System, GDPR-by-architecture, archival-ready
3. **Lightweight simplicity** — No Java stack, no separate deployment; runs inside existing Nextcloud with zero additional infrastructure

### Target Segments

| Segment | Why Procest | Competitors They'd Otherwise Use |
|---------|------------|--------------------------------|
| Small municipalities | Simple, affordable, NL-compliant | Spreadsheets, shared drives |
| Government teams | ZGW-ready, sovereign, NL Design | OpenZaak + custom frontend |
| SMB operations | Lightweight, integrated with existing tools | Monday.com, Jira |
| NGOs/nonprofits | Free, self-hosted, collaboration-first | Google Workspace, Trello |

### Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Feature gap vs enterprise BPM | High | Focus on case management, not process automation; don't compete with Camunda |
| ZGW standard compliance depth | Medium | Map to ZGW for interop; don't implement full ZGW runtime |
| User familiarity with existing tools | High | Polish UX from day one; provide migration tooling |
| Small team | High | Thin client architecture minimizes backend code; leverage OpenRegister |
| OpenRegister dependency | Medium | Actively developed, used by multiple apps |
| CMMN complexity | Medium | Start with simple lifecycle; add CMMN features incrementally |

## 5. Recommended Feature Set Summary

### MVP (27 features)

Replace spreadsheets and informal case tracking for small teams. Case types control behavior from day one.

**Case Management**
1. Case CRUD with lifecycle
2. Case list with search, sort, filters
3. Case detail view with timeline
4. Status timeline visualization on case detail
5. Case deadline countdown (days remaining / overdue)
6. Quick status change from case list view
7. Case result recording

**Case Type System**
8. Case type CRUD (admin)
9. Case type controls allowed statuses (ordered)
10. Case type controls processing deadline (auto-calculated)
11. Case type draft/published lifecycle
12. Case type validity periods

**Task Management**
13. Task CRUD linked to cases
14. Task list with status filters
15. Task assignment to users
16. Task due dates and priorities

**Roles & Status**
17. Case handler assignment (initiator, handler roles)
18. Status tracking with history

**My Work & Dashboard**
19. My Work view (personal workload: my cases, my tasks)
20. Overdue item highlighting
21. Dashboard with counts and status distribution

**Admin Settings**
22. Nextcloud admin settings page
23. Case type management UI
24. Status type management per case type
25. Default case type selection

**Platform**
26. RBAC via OpenRegister
27. Full audit trail, WCAG AA, English/Dutch localization

### V1 (34 additional features)

Compete with OpenZaak+frontend for government teams.

**Case Type Extensions**
28. Case type controls allowed roles
29. Case type controls result types (with archival rules)
30. Case type custom property definitions
31. Case type required documents per status
32. Case type decision type definitions
33. Case type confidentiality defaults
34. Case type suspension/extension rules

**Case Management**
35. Sub-cases (parent/child)
36. Document completion checklist (required vs present)
37. Property completion indicator (% required fields filled)
38. Days in current status indicator
39. Case templates
40. Confidentiality levels on cases

**Task Management**
41. Task checklist and dependencies
42. Kanban board for tasks
43. Task templates per case type

**Decisions**
44. Decision CRUD with effective/expiry dates

**Admin Settings**
45. Result type management per case type
46. Role type management per case type
47. Property/document/decision type management

**Reporting**
48. Case type breakdown chart (dashboard)
49. Average processing time per case type

**Collaboration**
50. Talk integration (per-case chat)
51. Calendar integration (deadlines in calendar)
52. Activity stream publishing
53. Status change notifications
54. File attachments and shared folders

**Integration**
55. Pipelinq bridge (request-to-case)
56. ZGW API mapping (Zaken, Besluiten, Catalogi)
57. Cross-app My Work (include Pipelinq leads/requests)

**Compliance & UX**
58. GDPR export + deletion
59. NL Design System theming
60. Saved views/filters
61. Configurable list columns

### Enterprise (21 additional features)

Large municipalities, multi-organization, and compliance-heavy deployments.

62. Federated case sharing
63. Case type sub-case type restrictions
64. Case type versioning chains
65. Case type import/export
66. CMMN runtime (sentries, criteria)
67. Nextcloud Flows automation
68. Automated task creation on status change
69. SLA compliance meter (% cases meeting deadline)
70. Case type performance comparison
71. Handler workload heatmap
72. Workload analytics (items per user)
73. DMN decision tables
74. Archival management (archiefwet)
75. Data retention policies
76. Field-level access control
77. Webhook support
78. Public intake form
79. Document templates
80. Bulk case operations
81. Workflow designer (visual)
82. Custom report builder
