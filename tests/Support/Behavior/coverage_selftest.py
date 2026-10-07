#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Check that malformed integration evidence cannot replace unit coverage."""
import argparse
import copy
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[3]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--unit', type=Path, required=True)
    parser.add_argument('--integration', type=Path, required=True)
    args = parser.parse_args()
    manifest = json.loads((args.integration / 'observations.json').read_text())
    poller = '/var/www/html/poller.php'
    measured = None
    for path in (args.integration / 'raw').glob('coverage-*.json'):
        report = json.loads(path.read_text())
        if 1 in report['files'].get(poller, {}).get('lines', {}).values():
            measured = {'php': report['php'], 'files': {poller: report['files'][poller]}}
            break
    if measured is None:
        raise RuntimeError('Self-test requires actual measured poller coverage')
    cases = ['stale-source', 'invalid-hit', 'invalid-line', 'missing-scenario',
             'missing-reports', 'unexecuted-poller', 'path-traversal']
    with tempfile.TemporaryDirectory(prefix='coverage-failure-') as directory:
        root = Path(directory)
        (root / 'raw').mkdir()
        raw = root / 'raw/coverage-probe.json'
        output = root / 'combined.xml'
        for case in cases:
            data = copy.deepcopy(measured)
            evidence = copy.deepcopy(manifest)
            lines = data['files'][poller]['lines']
            if case == 'stale-source':
                data['files'][poller]['sha256'] = '0' * 64
            elif case == 'invalid-hit':
                lines[next(iter(lines))] = 2
            elif case == 'invalid-line':
                lines['0'] = 1
            elif case == 'missing-scenario':
                evidence['scenarios'].pop('poller/run-reachable')
            elif case == 'unexecuted-poller':
                data['files'][poller]['lines'] = {key: -1 for key in lines}
            elif case == 'path-traversal':
                data['files']['/var/www/html/../html/poller.php'] = data['files'].pop(poller)
            raw.write_text(json.dumps(data))
            if case == 'missing-reports':
                raw.unlink()
            (root / 'observations.json').write_text(json.dumps(evidence))
            output.write_text('original unit coverage')
            result = subprocess.run([args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
                                     str(args.unit.resolve()), str(root), str(output)],
                                    capture_output=True, text=True, timeout=60)
            if result.returncode == 0 or output.read_text() != 'original unit coverage':
                raise RuntimeError(f'{case}: invalid evidence replaced unit coverage')
            expected = {'stale-source': 'Covered source differs', 'invalid-hit': 'Invalid PCOV',
                        'invalid-line': 'Invalid PCOV', 'missing-scenario': 'Incomplete integration',
                        'missing-reports': 'Missing integration', 'unexecuted-poller': 'No real poller',
                        'path-traversal': 'Invalid coverage source'}[case]
            if expected not in result.stdout + result.stderr:
                raise RuntimeError(f'{case}: failed for an unexpected reason: {result.stderr}')
            print(f'PASS {case}', flush=True)

        fixture_coverage = root / 'unit-with-temporary-source.php'
        fixture = subprocess.run(
            [args.php, str(ROOT / 'tests/Support/Behavior/coverage_source_map_fixture.php'),
             str(args.unit.resolve()), str(fixture_coverage)],
            capture_output=True, text=True, check=True, timeout=60)
        temporary = json.loads(fixture.stdout)
        # Renamed copies cannot be reconstructed by the suffix fallback. A
        # corrupt associated manifest must fail before publishing any report.
        temporary_manifest = Path(temporary['manifest'])
        valid_mapping = temporary_manifest.read_text()
        stale_mapping = json.loads(valid_mapping)
        stale_mapping['sha256'] = '0' * 64
        temporary_manifest.write_text(json.dumps(stale_mapping))
        output.write_text('original unit coverage')
        invalid = subprocess.run(
            [args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
             str(fixture_coverage), str(args.integration.resolve()), str(output)],
            capture_output=True, text=True, timeout=60)
        if invalid.returncode == 0 or output.read_text() != 'original unit coverage' \
                or 'Invalid associated coverage source mapping' not in invalid.stdout + invalid.stderr \
                or Path(temporary['copy']).exists() or not temporary_manifest.exists():
            raise RuntimeError('temporary-source-invalid-manifest: invalid mapping was consumed or published')
        print('PASS temporary-source-invalid-manifest', flush=True)
        for case in ('outside-copy', 'traversal-copy', 'outside-source'):
            invalid_mapping = json.loads(valid_mapping)
            if case == 'outside-copy':
                invalid_mapping['copy'] = '/coverage-map-outside/' + Path(temporary['copy']).name
            elif case == 'traversal-copy':
                copy_path = Path(temporary['copy'])
                invalid_mapping['copy'] = str(copy_path.parent / '..' / copy_path.parent.name / copy_path.name)
            else:
                invalid_mapping['source'] = str(root / 'observations.json')
            temporary_manifest.write_text(json.dumps(invalid_mapping))
            output.write_text('original unit coverage')
            rejected = subprocess.run(
                [args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
                 str(fixture_coverage), str(args.integration.resolve()), str(output)],
                capture_output=True, text=True, timeout=60)
            error = 'Invalid associated coverage source mapping' if case == 'outside-source' \
                else 'Unmapped temporary coverage source'
            if rejected.returncode == 0 or output.read_text() != 'original unit coverage' \
                    or error not in rejected.stdout + rejected.stderr \
                    or Path(temporary['copy']).exists() or not temporary_manifest.exists():
                raise RuntimeError(f'temporary-source-{case}: invalid mapping was consumed or published')
            print(f'PASS temporary-source-{case}', flush=True)
        temporary_manifest.write_text(valid_mapping)

        unrelated_fixture = subprocess.run(
            [args.php, str(ROOT / 'tests/Support/Behavior/coverage_source_map_fixture.php'),
             str(args.unit.resolve()), str(root / 'unrelated-coverage.php'), 'named'],
            capture_output=True, text=True, check=True, timeout=60)
        unrelated = json.loads(unrelated_fixture.stdout)
        unrelated_manifest = Path(unrelated['manifest'])
        unrelated_mapping = json.loads(unrelated_manifest.read_text())
        unrelated_copy = Path(unrelated['copy'])
        unrelated_copy.parent.mkdir(parents=True)
        unrelated_source = Path(unrelated_mapping['source']).read_bytes()
        unrelated_copy.write_bytes(unrelated_source)
        paired_coverage = root / 'paired-coverage.php'
        paired_fixture = subprocess.run(
            [args.php, str(ROOT / 'tests/Support/Behavior/coverage_source_map_fixture.php'),
             str(root / 'unrelated-coverage.php'), str(paired_coverage), 'named'],
            capture_output=True, text=True, check=True, timeout=60)
        paired = json.loads(paired_fixture.stdout)
        output.write_text('original unit coverage')
        try:
            result = subprocess.run(
                [args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
                 str(paired_coverage), str(args.integration.resolve()), str(output)],
                capture_output=True, text=True, timeout=60)
            if not unrelated_manifest.exists() or not unrelated_copy.exists() \
                    or unrelated_copy.read_bytes() != unrelated_source:
                raise RuntimeError('temporary-source-unrelated: another artifact lost its retained source or manifest')
            if result.returncode != 0 or Path(paired['copy']).exists() or Path(paired['manifest']).exists():
                raise RuntimeError('temporary-source-unrelated: associated mapping was not consumed successfully')
            print('PASS temporary-source-unrelated', flush=True)
            handoff_output = root / 'handoff.xml'
            handoff = subprocess.run(
                [args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
                 str(root / 'unrelated-coverage.php'), str(args.integration.resolve()), str(handoff_output)],
                capture_output=True, text=True, timeout=60)
            if handoff.returncode != 0 or unrelated_manifest.exists() or unrelated_copy.exists():
                raise RuntimeError('temporary-source-handoff: the preserved artifact cannot complete its own merge')
            print('PASS temporary-source-handoff', flush=True)
        finally:
            unrelated_copy.unlink(missing_ok=True)
            unrelated_manifest.unlink(missing_ok=True)
            if unrelated_copy.parent.exists():
                unrelated_copy.parent.rmdir()
            if unrelated_copy.parent.parent.exists():
                unrelated_copy.parent.parent.rmdir()
            Path(paired['manifest']).unlink(missing_ok=True)
            Path(paired['copy']).unlink(missing_ok=True)
        output.write_text('original unit coverage')
        result = subprocess.run(
            [args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
             str(fixture_coverage), str(args.integration.resolve()), str(output)],
            capture_output=True, text=True, timeout=60)
        if result.returncode != 0:
            raise RuntimeError(f'temporary-source-restore: {result.stderr}')
        if not output.is_file() or output.read_text() == 'original unit coverage':
            raise RuntimeError('temporary-source-restore: combined report was not published')
        if Path(temporary['copy']).exists() or Path(temporary['manifest']).exists():
            raise RuntimeError('temporary-source-restore: temporary source or manifest was not cleaned')
        canonical_source = json.loads(valid_mapping)['source']
        # Use the installed native XMLReader with network access and entity
        # substitution disabled; generated Clover must contain no DTD.
        clover_check = r'''
libxml_use_internal_errors(true);
libxml_clear_errors();
$reader = new XMLReader();
if (!$reader->open($argv[1], null, LIBXML_NONET)) { exit(1); }
if (!$reader->setParserProperty(XMLReader::LOADDTD, false)
    || !$reader->setParserProperty(XMLReader::SUBST_ENTITIES, false)) { exit(1); }
$matches = 0; $alias = false; $hit = false; $inside = false;
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::DOC_TYPE) { exit(1); }
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'file') {
        $inside = $reader->getAttribute('name') === $argv[2];
        $matches += $inside ? 1 : 0;
        $alias = $alias || $reader->getAttribute('name') === $argv[3];
    } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'file') {
        $inside = false;
    } elseif ($inside && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'line') {
        $hit = $hit || (int) $reader->getAttribute('count') > 0;
    }
}
$reader->close();
exit($matches === 1 && !$alias && $hit && libxml_get_errors() === [] ? 0 : 1);
'''
        parser_fixture = root / 'clover-parser-control.xml'
        minimal_clover = '<clover><project><file name="/measured.php"><line count="1"/></file></project></clover>'
        for xml, expected in ((minimal_clover, 0), ('<!DOCTYPE clover>' + minimal_clover, 1)):
            parser_fixture.write_text(xml)
            parser_control = subprocess.run(
                [args.php, '-r', clover_check, str(parser_fixture), '/measured.php', '/alias.php'],
                capture_output=True, text=True, timeout=60)
            if parser_control.returncode != expected:
                raise RuntimeError('temporary-source-safe-xml-parser: valid report or DTD refusal failed')
        print('PASS temporary-source-safe-xml-parser', flush=True)
        checked_clover = subprocess.run(
            [args.php, '-r', clover_check, str(output), canonical_source, temporary['copy']],
            capture_output=True, text=True, timeout=60)
        if checked_clover.returncode != 0:
            raise RuntimeError('temporary-source-restore: measured coverage was lost or the copied path leaked into Clover')
        print('PASS temporary-source-restore', flush=True)


if __name__ == '__main__':
    main()
