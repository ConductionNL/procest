## Why

Procest exposes ZGW-compliant APIs (Zaken, Catalogi, Documenten, Besluiten) on top of OpenRegister, but currently fails ~56 out of 353 business rule assertions in the VNG Newman test suite. Full compliance is required for production use by Dutch municipalities. The business rules enforce data integrity, authorization, side effects (zaak closing/reopening, archive derivation), and cross-register consistency that are mandatory per the VNG ZGW standard.

Additionally, current ZGW endpoints are significantly slower than expected (~2-5s per request vs the expected ~180ms) due to manual enrichment logic instead of leveraging OpenRegister's optimized property inversion and search methods. Performance must be addressed alongside correctness.

## What Changes

- **zrc-007a**: Fix eindstatus detection — set zaak `einddatum` when the statustype with the highest `volgnummer` is created (fallback when `isEindstatus` is not explicitly set)
- **zrc-007b**: Set `indicatieGebruiksrecht` on all linked informatieobjecten when zaak closes
- **zrc-007q**: Validate all linked informatieobjecten have `indicatieGebruiksrecht` set before allowing eindstatus creation
- **zrc-008c**: Check `zaken.heropenen` scope before allowing reopening of a closed zaak
- **zrc-010**: Fix communicatiekanaal validation error codes (`bad-url` vs `invalid-resource`)
- **zrc-013a**: Fix hoofdzaak not-found error code (`does-not-exist` instead of `no_match`)
- **zrc-015**: Validate productenOfDiensten are a subset of zaaktype's allowed products
- **zrc-016/018/019/020**: Cross-validate sub-resource types belong to zaak's zaaktype
- **zrc-021**: Derive archiefactiedatum from resultaattype's brondatumArchiefprocedure
- **zrc-002**: Enforce identificatie + bronorganisatie uniqueness
- **zrc-005b/023h**: Cascade-delete ObjectInformatieObject when ZaakInformatieObject or zaak is deleted
- **zrc-009**: Derive vertrouwelijkheidaanduiding from zaaktype without template leakage
- **zrc-006**: Filter zaken results based on consumer's authorized zaaktypen and vertrouwelijkheidaanduiding
- **Performance**: Refactor enrichment logic to use OpenRegister's optimized property inversion and search methods instead of manual cross-register lookups

## Capabilities

### New Capabilities
- `zgw-business-rules`: ZRC/ZTC/DRC/BRC business rule validation and enrichment — covers all VNG-numbered rules (zrc-001 through zrc-023, brc-001 through brc-006, drc-001 through drc-003, ztc-001 through ztc-010)
- `zgw-endpoint-performance`: Optimize ZGW endpoint response times by leveraging OpenRegister's property inversion, eager loading, and optimized search instead of manual N+1 cross-register lookups

### Modified Capabilities
- `procest-case-management`: ZGW zaak lifecycle side effects (closing, reopening, archive derivation) are tightened to match VNG standard exactly
- `procest-object-store`: Cross-register sync (OIO creation/deletion) and cascade delete behavior changes

## Impact

- **Code**: `ZrcController.php`, `ZgwZrcRulesService.php`, `ZgwRulesBase.php`, `ZgwBusinessRulesService.php`, `ZgwService.php`, `ZgwZtcRulesService.php`, `ZgwDrcRulesService.php`, `ZgwBrcRulesService.php`
- **APIs**: All ZGW endpoints (`/api/zgw/{zaken,catalogi,documenten,besluiten}/v1/*`) — validation responses and side effects change
- **Dependencies**: OpenRegister ObjectService (property inversion, optimized search), OpenRegister AuthorizationService (scope checking)
- **Testing**: Newman business rules collection must reach 353/353 assertions passing (0 failures)
- **Performance target**: Average endpoint response time under 200ms (currently 2-5s)
