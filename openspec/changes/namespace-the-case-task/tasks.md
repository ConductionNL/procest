# Tasks

- [x] 1.1 Move the key, slug and register-list entry, and the manifest references
      **files**: lib/Settings/dossiq_register.json, lib/Settings/dossiq_mock_register.json, src/manifest.json
- [x] 2.1 Move the slug in the config-key map and the JSON-encoded property map
      **files**: lib/Service/Settings/SchemaSlugMap.php, lib/Service/Support/JsonEncodedStringProperties.php
- [x] 2.2 Move the schema-id map key in BOTH builders and their readers, and the declared array shapes
      **files**: lib/Service/KpiAggregationService.php, lib/Service/DemoCaseloadGateway.php, lib/Service/DemoCaseloadReport.php, lib/Service/DemoCaseloadSeedDataService.php
- [x] 2.3 Follow the slug through the frontend object-type registration and store calls
      **files**: src/store/store.js, src/store/modules/advice.js, src/store/modules/inspection.js, src/store/modules/enforcement.js, src/store/modules/workflow.js, src/views/widgets/TaskRemindersWidget.vue, src/views/widgets/MyTasksWidget.vue, src/components/flow/TaskWaitingCaseSection.vue, src/components/tabs/CaseTasksTab.vue
- [x] 3.1 Add the rename to the colliding-slug map
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php
- [x] 4.1 Move the slug in the e2e seed's required-schema list
      **files**: tests/e2e/ci-seed.sh
- [x] 4.2 Repoint the test stubs, leaving item and row type labels alone
      **files**: tests/Unit/, tests/vitest/
