<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\AddGraphs;
use Kadupul\Graphing\Application\Command\GraphAddition;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphRequest;
use Kadupul\Graphing\Application\Command\GraphResult;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphs;
use PHPUnit\Framework\TestCase;

final class AddGraphsTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 5);
    }

    private function add(InMemoryGraphs $graphs, GraphRequest $request): GraphAddition
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));

        return (new AddGraphs($access, $graphs, $graphs))(false, null, $request);
    }

    private function request(mixed ...$options): GraphRequest
    {
        return new GraphRequest(...['hostId' => '7', 'templateId' => '3', 'graphType' => 'cg', ...$options]);
    }

    public function testTheListsAreAnsweredInTheOrderTheScriptTriedThem(): void
    {
        $graphs = new InMemoryGraphs();
        $all = new GraphRequest(listTemplates: true, listHosts: true, listQueries: true);

        self::assertSame(GraphAddition::TEMPLATES, $this->add($graphs, $all)->status);
        self::assertSame(GraphAddition::DEVICES, $this->add($graphs, new GraphRequest(listHosts: true, listQueries: true))->status);
        self::assertSame(GraphAddition::QUERIES, $this->add($graphs, new GraphRequest(listQueries: true, listFields: true, hostId: '7'))->status);
        self::assertSame(GraphAddition::INPUT_FIELDS, $this->add($graphs, new GraphRequest(listInputFields: true, listTemplates: false, templateId: '4'))->status);
    }

    public function testTheGraphTemplatesOfADeviceTemplateAreListedOnlyForAPositiveNumber(): void
    {
        $graphs = new InMemoryGraphs();

        self::assertSame(['Load'], array_map(static fn($choice): string => $choice->name, $this->add($graphs, new GraphRequest(listTemplates: true, hostTemplateId: '2'))->choices));
        self::assertSame(GraphAddition::BAD_HOST_TEMPLATE, $this->add($graphs, new GraphRequest(listTemplates: true, hostTemplateId: '9'))->status, 'none attached');
        // The script compared the text with 0 by PHP's rules, so any text that sorts after "0" counted as positive and was then refused as not a number.
        self::assertSame(GraphAddition::BAD_HOST_TEMPLATE, $this->add($graphs, new GraphRequest(listTemplates: true, hostTemplateId: 'abc'))->status);
        foreach (['0', '', '-1', '-'] as $zero) {
            self::assertCount(2, $this->add($graphs, new GraphRequest(listTemplates: true, hostTemplateId: $zero))->choices, "'{$zero}' lists every template");
        }
    }

    public function testInputFieldsNeedAPositiveTemplate(): void
    {
        self::assertSame(GraphAddition::NEEDS_TEMPLATE, $this->add(new InMemoryGraphs(), new GraphRequest(listInputFields: true, templateId: '0'))->status);
    }

    public function testAnIdIsFoundOnlyByTheCanonicalFormOfAnArrayKey(): void
    {
        $graphs = new InMemoryGraphs();
        self::assertSame(GraphAddition::DONE, $this->add($graphs, $this->request())->status);
        foreach (['07', '7.0', ' 7', '7 ', '7e0', '+7'] as $device) {
            $result = $this->add($graphs, $this->request(hostId: $device));
            self::assertSame([GraphAddition::UNKNOWN_HOST, $device], [$result->status, $result->subject]);
        }
        self::assertSame(GraphAddition::UNKNOWN_HOST, $this->add($graphs, $this->request(hostId: '0'))->status);
        self::assertSame(GraphAddition::UNKNOWN_TEMPLATE, $this->add($graphs, $this->request(templateId: '03'))->status);
        self::assertSame(GraphAddition::UNKNOWN_QUERY, $this->add($graphs, $this->request(queryId: '1.0'))->status);
        self::assertSame(GraphAddition::UNKNOWN_QUERY_TYPE, $this->add($graphs, $this->request(queryId: '1', queryType: '9'))->status);
    }

    public function testADataQueryIsAssociatedOnceAndOnlyForARealDevice(): void
    {
        $graphs = new InMemoryGraphs();
        $this->add($graphs, $this->request(hostId: '8', graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName'], snmpValues: ['eth0'], reindexMethod: 3));
        self::assertSame(['8:1:3'], $graphs->associations);

        $graphs = new InMemoryGraphs();
        $this->add($graphs, $this->request(hostId: '7', graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName'], snmpValues: ['eth0']));
        $this->add($graphs, $this->request(hostId: '8', queryId: '1', listFields: true));
        $this->add($graphs, $this->request(hostId: '99', queryId: '1', graphType: 'ds'));
        self::assertSame([], $graphs->associations, 'cached already, only listing, and no such device');
    }

    public function testAFailedAssociationStopsTheRun(): void
    {
        $graphs = new InMemoryGraphs(failFrom: 1);

        $result = $this->add($graphs, $this->request(hostId: '8', graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName'], snmpValues: ['eth0']));

        self::assertSame(GraphAddition::FAILED, $result->status);
        self::assertSame([], $graphs->drafts);
    }

    public function testTheFieldChecksRunForTheListModesAndTheDqType(): void
    {
        $graphs = new InMemoryGraphs();
        $fields = $this->add($graphs, $this->request(listFields: true));
        self::assertSame([GraphAddition::FIELDS, ['ifName', 'ifStatus']], [$fields->status, $fields->names]);

        $cases = [
            [['snmpFields' => ['ifName'], 'snmpValues' => ['eth0'], 'snmpValueExpressions' => ['^eth']], GraphAddition::BOTH_VALUE_KINDS],
            [['snmpFields' => ['ifName', 'ifStatus'], 'snmpValues' => ['eth0']], GraphAddition::VALUE_COUNT],
            [['snmpFields' => ['ifName'], 'snmpValueExpressions' => ['^eth', '^lo']], GraphAddition::EXPRESSION_COUNT],
            [['snmpFields' => ['ifName']], GraphAddition::NEEDS_VALUE],
            [['snmpFields' => ['nothing'], 'snmpValues' => ['x']], GraphAddition::UNKNOWN_FIELD],
            [['snmpFields' => ['ifName'], 'snmpValues' => ['nothing']], GraphAddition::UNKNOWN_VALUE],
            [['snmpFields' => ['ifName'], 'snmpValueExpressions' => ['^zz']], GraphAddition::UNKNOWN_VALUE],
        ];
        foreach ($cases as [$options, $status]) {
            self::assertSame($status, $this->add($graphs, $this->request(...['graphType' => 'dq', ...$options]))->status, $status);
        }
        // 'dq' passes the checks and then fails as an unknown type, as the script did.
        self::assertSame(GraphAddition::BAD_TYPE, $this->add($graphs, $this->request(graphType: 'dq', snmpFields: ['ifName'], snmpValues: ['eth0']))->status);
    }

    public function testAValueMatchesLooselyAndZeroOrEmptyIsNotChecked(): void
    {
        $graphs = new InMemoryGraphs();

        self::assertSame(GraphAddition::BAD_TYPE, $this->add($graphs, $this->request(graphType: 'dq', snmpFields: ['ifName'], snmpValues: ['1.0']))->status, '1.0 equals the known 1');
        self::assertSame(GraphAddition::UNKNOWN_VALUE, $this->add($graphs, $this->request(graphType: 'dq', snmpFields: ['ifName'], snmpValues: ['abc']))->status);
        self::assertSame(GraphAddition::BAD_TYPE, $this->add($graphs, $this->request(graphType: 'dq', snmpFields: ['ifName'], snmpValues: ['0']))->status, '"0" is falsy, so it is not checked');
        self::assertSame(GraphAddition::BAD_TYPE, $this->add($graphs, $this->request(graphType: 'dq', snmpFields: ['ifName'], snmpValues: ['']))->status);
    }

    public function testTheValuesOfTheLastFieldArePrintedOncePerFieldGiven(): void
    {
        $graphs = new InMemoryGraphs();

        $result = $this->add($graphs, $this->request(listValues: true, snmpFields: ['ifName', 'ifStatus']));

        self::assertSame([GraphAddition::VALUES, ['Up', 'Down'], 2], [$result->status, $result->names, $result->repeats]);
        self::assertSame(GraphAddition::NEEDS_FIELD, $this->add($graphs, $this->request(listValues: true))->status);
    }

    public function testTheDataQueryChecksDoNotRunForADataQueryGraph(): void
    {
        $graphs = new InMemoryGraphs();

        $result = $this->add($graphs, $this->request(graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['nothing'], snmpValues: ['nothing']));

        self::assertSame(GraphAddition::DONE, $result->status);
    }

    public function testAGraphIsCreatedFromATemplateOnceUnlessForced(): void
    {
        $graphs = new InMemoryGraphs(existing: ['3:7' => 41]);

        $exists = $this->add($graphs, $this->request());
        self::assertSame(GraphAddition::EXISTS, $exists->status);
        self::assertSame('1041', $exists->graphs[0]->dataSources);
        self::assertSame([], $graphs->drafts);

        $forced = $this->add($graphs, $this->request(force: true, graphTitle: 'Mine'));
        self::assertSame([GraphAddition::DONE, GraphResult::CREATED, 101, '201'], [$forced->status, $forced->graphs[0]->kind, $forced->graphs[0]->graphId, $forced->graphs[0]->dataSources]);
        self::assertSame(['Mine', 3, 7, null], [$graphs->drafts[0]->title, $graphs->drafts[0]->templateId, $graphs->drafts[0]->deviceId, $graphs->drafts[0]->queryId]);
    }

    public function testAGraphTheWhitelistRefusesIsReportedAndNotAnError(): void
    {
        $result = $this->add(new InMemoryGraphs(refuseFrom: 1), $this->request());

        self::assertSame([GraphAddition::DONE, GraphResult::REFUSED], [$result->status, $result->graphs[0]->kind]);
    }

    public function testInputFieldsAreParsedAsTheScriptReadThem(): void
    {
        $graphs = new InMemoryGraphs();

        $this->add($graphs, $this->request(templateId: '4', inputFields: 'index_type=ifIndex 9:index_value=a=b index_t'));

        self::assertSame([['9', '21', 'ifIndex'], ['9', '22', 'a'], ['9', null, null]], $graphs->drafts[0]->customData, 'the value ends at a second equals sign, and a part of a name files it under no field');
    }

    public function testAnUnknownInputFieldIsRefusedBeforeAnythingIsMade(): void
    {
        $graphs = new InMemoryGraphs();

        $result = $this->add($graphs, $this->request(templateId: '4', inputFields: 'index_type=1 nothing=2'));

        self::assertSame([GraphAddition::UNKNOWN_INPUT, 'nothing'], [$result->status, $result->subject]);
        self::assertSame([], $graphs->drafts);
        self::assertSame(GraphAddition::UNKNOWN_INPUT, $this->add($graphs, $this->request(inputFields: 'index_type=1'))->status, 'a template without input fields');
    }

    public function testADataQueryGraphIsMadeForEachIndexAndExistingOnesAreRetitled(): void
    {
        $graphs = new InMemoryGraphs(indexes: ['1', '2', '3'], existing: ['3:7:2' => 55]);

        $result = $this->add($graphs, $this->request(graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName'], snmpValues: ['eth0'], graphTitle: 'New'));

        self::assertSame(GraphAddition::DONE, $result->status);
        self::assertSame([GraphResult::CREATED, GraphResult::EXISTS, GraphResult::CREATED], array_map(static fn(GraphResult $graph): string => $graph->kind, $result->graphs));
        self::assertSame(['55:New'], $graphs->titles);
        self::assertSame('501, 201', $result->graphs[0]->dataSources, 'the first data source of the graph, then each the creation made');
        self::assertSame('1055', $result->graphs[1]->dataSources);
        self::assertSame(['1', '3'], array_map(static fn($draft): ?string => $draft->queryIndex, $graphs->drafts));
    }

    public function testAnExistingDataQueryGraphKeepsItsTitleWhenNoneIsGiven(): void
    {
        $graphs = new InMemoryGraphs(indexes: ['2'], existing: ['3:7:2' => 55]);

        $this->add($graphs, $this->request(graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName'], snmpValues: ['eth0']));

        self::assertSame([], $graphs->titles);
    }

    public function testTheFiltersPairEachFieldWithItsValueOrItsExpression(): void
    {
        $graphs = new InMemoryGraphs();

        $this->add($graphs, $this->request(graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName', 'ifStatus'], snmpValues: ['eth0'], snmpValueExpressions: ['x', '^Up']));

        self::assertSame([['ifName', 'eth0', null], ['ifStatus', null, '^Up']], array_map(static fn($filter): array => [$filter->field, $filter->value, $filter->expression], $graphs->filters));
    }

    public function testAFieldWithNeitherValueNorExpressionFindsNoIndex(): void
    {
        $graphs = new InMemoryGraphs();

        $result = $this->add($graphs, $this->request(graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName', 'ifStatus'], snmpValues: ['eth0']));

        self::assertSame([GraphAddition::NO_INDEXES, 'core1', 'eth0'], [$result->status, $result->subject, $result->detail]);
        self::assertSame([], $graphs->filters, 'the query is not run');
    }

    public function testADataQueryGraphNeedsItsQueryTypeAndFields(): void
    {
        $graphs = new InMemoryGraphs();

        foreach ([['queryId' => '1', 'queryType' => '2'], ['queryId' => '1', 'snmpFields' => ['ifName']], ['queryType' => '2', 'snmpFields' => ['ifName']]] as $options) {
            self::assertSame(GraphAddition::NEEDS_OPTIONS, $this->add($graphs, $this->request(...['graphType' => 'ds', ...$options]))->status, json_encode($options, JSON_THROW_ON_ERROR));
        }
    }

    public function testAFailedCreationKeepsWhatWasDoneBefore(): void
    {
        $graphs = new InMemoryGraphs(indexes: ['1', '2'], failFrom: 2);

        $result = $this->add($graphs, $this->request(graphType: 'ds', queryId: '1', queryType: '2', snmpFields: ['ifName'], snmpValues: ['eth0']));

        self::assertSame(GraphAddition::FAILED, $result->status);
        self::assertCount(1, $result->graphs);
    }

    public function testAnOperatorWithoutGraphManagementWritesNothing(): void
    {
        $graphs = new InMemoryGraphs();
        $this->db = $this->operatorDatabase(8, 3);

        try {
            $this->add($graphs, $this->request());
            self::fail('The run was not refused.');
        } catch (GraphManagementDenied) {
            self::assertSame([], $graphs->drafts);
        }
        $this->expectException(GraphManagementDenied::class);
        $this->add($graphs, new GraphRequest(listHosts: true));
    }
}
