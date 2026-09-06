<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqSendEmailNode;
use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\Actions\SendEmailHandler;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Covers the action-node wrapper.
 *
 * SendEmail stands in for all six — they differ only in their handler and their
 * required keys, and the behaviour worth pinning lives in the shared base.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class DossiqActionNodeTest extends TestCase {

    /**
     * @var SendEmailHandler&\PHPUnit\Framework\MockObject\MockObject
     */
    private $handler;

    /**
     * @var DossiqSendEmailNode
     */
    private DossiqSendEmailNode $node;


    /**
     * Set up the node over a mocked handler.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->handler = $this->createMock(SendEmailHandler::class);
        $this->handler->method('type')->willReturn('sendEmail');

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params=[]): string {
                return vsprintf($text, $params);
            }
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');

        $this->node = new DossiqSendEmailNode($this->handler, $l10n, $urls);

    }//end setUp()


    /**
     * A complete config for this node.
     *
     * @return array<string, mixed> The config.
     */
    private function config(): array {
        return [
            'recipientRef'    => 'behandelaar',
            'subjectTemplate' => 'Zaak {{ title }}',
            'bodyTemplate'    => 'Uw zaak is bijgewerkt.',
        ];

    }//end config()


    /**
     * The node id is derived from the handler's own type slug.
     *
     * Deriving it is what stops a node id drifting from the handler it runs,
     * and gives the reference migration one rule instead of six.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testIdIsDerivedFromTheHandlerType(): void {
        // `dossiq.action.*`, not `dossiq.*`: the LIVE transition vocabulary
        // owns the plain names and both systems ship a sendEmail. An id
        // collision here would have one handler silently shadow the other in
        // the catalogue.
        $this->assertSame('dossiq.action.sendEmail', $this->node->getId());

    }//end testIdIsDerivedFromTheHandlerType()


    /**
     * A successful action puts its data on the item.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testSuccessfulActionWritesItsResultOntoTheItem(): void {
        $this->handler->method('handle')->willReturn(new ActionResult(true, null, ['sent' => 1]));

        $out = $this->node->execute(
            [['json' => ['id' => 'case-1', 'title' => 'Bezwaar']]],
            $this->config(),
            []
        );

        $this->assertCount(1, $out);
        $this->assertSame(['sent' => 1], $out[0]['json']['actionResult']);
        $this->assertSame('case-1', $out[0]['json']['id']);

    }//end testSuccessfulActionWritesItsResultOntoTheItem()


    /**
     * A handler's case writes travel with the outgoing item.
     *
     * The handler stores its field through the partial-write seam; the NEXT
     * step's snapshot must already carry it, or that step reasons from a case
     * that predates its own flow. This is how `besluitDocument` went missing
     * live: stored by the document step, absent from the item, erased by the
     * next step's save.
     *
     * @return void
     *
     * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
     */
    public function testCaseChangesAreStampedOntoTheOutgoingItem(): void {
        $this->handler->method('handle')->willReturn(
            new ActionResult(true, null, ['rendered' => 'Besluit'], ['besluitDocument' => 'Besluit'])
        );

        $out = $this->node->execute(
            [['json' => ['id' => 'case-1', 'title' => 'Bezwaar']]],
            $this->config(),
            []
        );

        $this->assertSame('Besluit', $out[0]['json']['besluitDocument']);
        $this->assertSame('case-1', $out[0]['json']['id']);

    }//end testCaseChangesAreStampedOntoTheOutgoingItem()


    /**
     * The output key is configurable.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testOutputKeyIsConfigurable(): void {
        $this->handler->method('handle')->willReturn(new ActionResult(true, null, ['sent' => 1]));

        $out = $this->node->execute(
            [['json' => []]],
            array_merge($this->config(), ['output' => 'mailResult']),
            []
        );

        $this->assertArrayHasKey('mailResult', $out[0]['json']);

    }//end testOutputKeyIsConfigurable()


    /**
     * A FAILED action throws instead of passing the item through.
     *
     * This is the one that matters. Returning the item unchanged would leave
     * the output key absent, and a downstream router would take its default
     * branch exactly as though the action had succeeded — the engine's onError
     * policy only ever sees failures that propagate out of execute().
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testFailedActionThrowsRatherThanPassingThrough(): void {
        $this->handler->method('handle')->willReturn(new ActionResult(false, 'smtp_unavailable'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('smtp_unavailable');

        $this->node->execute([['json' => []]], $this->config(), []);

    }//end testFailedActionThrowsRatherThanPassingThrough()


    /**
     * A config missing a required key is rejected at EXECUTION, not only on save.
     *
     * validateConfig() runs when a flow is saved; a seeded or imported flow
     * reaches execute() without ever having been saved through the editor.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnvalidatedConfigIsRejectedAtExecution(): void {
        $this->handler->expects($this->never())->method('handle');

        $config = $this->config();
        unset($config['recipientRef']);

        $this->expectException(UnexpectedValueException::class);
        $this->node->execute([['json' => []]], $config, []);

    }//end testUnvalidatedConfigIsRejectedAtExecution()


    /**
     * validateConfig() names the key it is missing.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testValidateConfigNamesTheMissingKey(): void {
        $config = $this->config();
        unset($config['bodyTemplate']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('bodyTemplate');

        $this->node->validateConfig($config);

    }//end testValidateConfigNamesTheMissingKey()


    /**
     * Every item in the batch is acted on.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testEveryItemInTheBatchIsActedOn(): void {
        $this->handler->expects($this->exactly(3))->method('handle')
            ->willReturn(new ActionResult(true, null, []));

        $out = $this->node->execute(
            [['json' => ['id' => 'a']], ['json' => ['id' => 'b']], ['json' => ['id' => 'c']]],
            $this->config(),
            []
        );

        $this->assertCount(3, $out);

    }//end testEveryItemInTheBatchIsActedOn()


}//end class
