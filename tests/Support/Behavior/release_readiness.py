"""Rehearse a real release upgrade and snapshot rollback in disposable containers."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
import uuid
from types import SimpleNamespace

import harness

ROOT = harness.ROOT


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def checked(result, label):
    require(result['exit'] == 0, label + ': ' + json.dumps(result))
    return result


def rrd_manifest(h):
    result = checked(h.php('-r', '$r=[]; foreach (glob("rra/*.rrd") as $p) {$r[basename($p)]=hash_file("sha256",$p);} ksort($r); echo json_encode($r);'), 'RRD manifest')
    values = json.loads(result['stdout'])
    require(isinstance(values, dict) and values, 'No RRD files were created')
    return values


def domain_state(h):
    return {table: h.sql('SELECT * FROM ' + table + ' ORDER BY ' + key)
            for table, key in [('host', 'id'), ('data_local', 'id'), ('graph_local', 'id'),
                               ('plugin_config', 'id'), ('plugin_hooks', 'id')]}


def authenticate(h):
    h.base = 'http://' + h.compose('port', 'web', '80')['stdout'].strip()
    session = harness.Session(h.base)
    result = session.login('behavior-admin')
    require(result['admin_layout'] and not result['login_form'], 'Admin login failed')
    return session


def assert_graph(h):
    graph = h.probe('graph')['result']
    require(isinstance(graph, dict) and bool(graph.get('source')), 'Graph definition is empty')
    # Drive the production rendering function, then inspect the returned PNG.
    code = '''chdir('/var/www/html'); $no_http_headers=true; include 'include/global.php'; include_once 'lib/rrd.php';
$id=(int)db_fetch_cell('SELECT MIN(gti.local_graph_id) FROM graph_templates_item gti INNER JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id INNER JOIN data_local dl ON dl.id=dtr.local_data_id WHERE gti.local_graph_id>0');
ob_start(); $png=rrdtool_function_graph($id,0,array('graph_start'=>time()-3600,'graph_end'=>time(),'graph_width'=>400,'graph_height'=>120,'image_format'=>'png')); $diagnostics=ob_get_clean();
if (!is_string($png) || substr($png,0,8)!=="\\x89PNG\\r\\n\\x1a\\n") {fwrite(STDERR,'Graph did not return PNG: '.substr((string)$png.$diagnostics,0,200));exit(1);} echo json_encode(array('signature'=>'PNG','bytes'=>strlen($png)));'''
    return json.loads(checked(h.php('-r', code), 'Graph rendering')['stdout'])


def assert_plugin(h):
    observed = h.probe('plugin')
    result = observed['result']
    require(result.get('callbacks_observed') == 8, 'Plugin did not dispatch every callback')
    require(len(result.get('filter', [])) == 7 and all(row['input'] == row['result'] for row in result['filter']),
            'Plugin changed filter values')
    return observed


def assert_poll(h, label):
    h.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log', 'plugin.jsonl')
    result = checked(h.php('poller.php', '--force'), label)
    calls = h.rrd_calls()
    require(any(call.startswith('update ') for call in calls), label + ' made no RRD updates')
    events = [row['args'][0][0] for row in h.jsonl('/artifacts/plugin.jsonl')
              if row.get('callback') == 'event' and row.get('args')]
    require('poller_top' in events and 'poller_bottom' in events, label + ' skipped plugin lifecycle hooks')
    return {'command': result, 'rrd_calls': calls, 'plugin_events': events}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', default='6482af547c204199e829b7a0df0b7a13db3e0a58')
    parser.add_argument('--output', type=Path, default=ROOT / 'tests/behavior/results/release-readiness')
    args = parser.parse_args()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    project = 'kadupul-release-' + uuid.uuid4().hex
    evidence = {'complete': False, 'baseline_requested': args.baseline,
                'project': project, 'php_requested': os.environ.get('PHP_VERSION', '8.2'), 'steps': {}}
    h = None
    try:
        baseline_revision = checked(harness.run(['git', '-C', str(ROOT), 'rev-parse', '--verify', '--end-of-options', args.baseline + '^{commit}'], check=False), 'Baseline revision')['stdout'].strip()
        evidence['baseline'] = baseline_revision
        evidence['candidate'] = checked(harness.run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], check=False), 'Candidate revision')['stdout'].strip()
        with tempfile.TemporaryDirectory(prefix='kadupul-release-') as temporary:
            temp = Path(temporary)
            baseline = temp / 'baseline'
            baseline.mkdir()
            archive = temp / 'baseline.tar'
            with archive.open('wb') as stream:
                subprocess.run(['git', '-C', str(ROOT), 'archive', baseline_revision], stdout=stream, check=True)
            with tarfile.open(archive) as source:
                source.extractall(baseline, filter='data')
            # The test infrastructure is candidate-owned; the application and
            # schema are the exact baseline revision exported above.
            shutil.copytree(ROOT / 'tests/Support/Behavior', baseline / 'tests/Support/Behavior', dirs_exist_ok=True)
            shutil.copytree(ROOT / 'tests/Fixtures', baseline / 'tests/Fixtures', dirs_exist_ok=True)
            shutil.copytree(ROOT / 'tests/behavior', baseline / 'tests/behavior', dirs_exist_ok=True,
                            ignore=shutil.ignore_patterns('results', '__pycache__'))
            shutil.copy2(ROOT / '.dockerignore', baseline / '.dockerignore')
            harness.ROOT = baseline
            h = harness.Harness(SimpleNamespace(target='release-readiness', only=None, update_golden=False, project=project))
            # Use a dedicated project and keep the baseline image for rollback.
            h.dc = ['docker', 'compose', '-p', project, '-f', str(baseline / 'tests/behavior/compose.yml')]
            override = temp / 'phase.json'
            def phase(tree, image):
                override.write_text(json.dumps({'services': {'web': {'image': image, 'build': {'context': str(tree)}}}}))
                h.dc = h.dc[:6] + ['-f', str(override)]
            phase(baseline, project + '-baseline:local')
            h.setup()
            authenticate(h)
            h.poller_scenarios()
            checked(h.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--install'), 'Plugin install')
            checked(h.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--enable'), 'Plugin enable')
            h.sql("REPLACE INTO settings(name,value) VALUES ('graph_watermark','Operations custom watermark');")
            before_rrd = rrd_manifest(h)
            before_domain = domain_state(h)
            evidence['steps']['baseline'] = {'version': h.sql('SELECT cacti FROM version').strip(),
                                            'rrd': before_rrd, 'graph': assert_graph(h),
                                            'runtime': h.base_image_digest()}
            # All poller calls are synchronous; no scheduled poller service is
            # started. Stop Apache before snapshotting the database and files.
            h.compose('stop', 'web')
            dump = h.compose('exec', '-T', 'db', 'mariadb-dump', '-uroot', '-pbehavior-root',
                             '--skip-comments', '--skip-dump-date', '--hex-blob', 'cacti')['stdout']
            snapshot = temp / 'rra'
            h.compose('cp', 'web:/var/www/html/rra', str(snapshot))
            evidence['snapshot_sha256'] = hashlib.sha256(dump.encode()).hexdigest()
            phase(ROOT, project + '-candidate:local')
            h.compose('up', '-d', '--build', '--wait', '--no-deps', 'web', timeout=1200)
            h.compose('cp', str(snapshot) + '/.', 'web:/var/www/html/rra')
            h.compose('exec', '-T', 'web', 'chown', '-R', 'www-data:www-data', '/var/www/html/rra')
            upgrade = checked(h.php('cli/install_cacti.php', '--accept-eula', '--install', '--mode=3', '--force'), 'Upgrade')
            actual_version = h.sql('SELECT cacti FROM version').strip()
            evidence['steps']['upgrade_attempt'] = {'command': upgrade, 'database_version': actual_version, 'source_version': checked(h.php('-r', 'echo file_get_contents("include/cacti_version");'), 'Source version')['stdout'].strip()}
            require(actual_version == (ROOT / 'include/cacti_version').read_text().strip(), 'Upgrade version mismatch: ' + actual_version)
            require(rrd_manifest(h) == before_rrd, 'Upgrade modified RRD bytes')
            require(domain_state(h) == before_domain, 'Upgrade changed device, source, graph or plugin identities')
            require(h.sql("SELECT value FROM settings WHERE name='graph_watermark'").strip() == 'Operations custom watermark', 'Upgrade changed custom watermark')
            authenticate(h)
            graph = assert_graph(h)
            plugin = assert_plugin(h)
            repeat = checked(h.php('cli/install_cacti.php', '--accept-eula', '--install', '--mode=3', '--force'), 'Repeated upgrade')
            require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, 'Repeated upgrade changed persisted data')
            poll = assert_poll(h, 'Candidate poller')
            evidence['steps']['upgrade'] = {'command': upgrade, 'repeat': repeat, 'graph': graph, 'plugin': plugin,
                                            'poller': poll, 'rrd_preserved_before_poll': True}
            # Restore the old code AND its matching DB/RRD snapshot. A code-only
            # downgrade is not an acceptable rollback of a schema upgrade.
            h.compose('stop', 'web')
            h.sql('DROP DATABASE cacti; CREATE DATABASE cacti CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            h.sql(dump)
            phase(baseline, project + '-baseline:local')
            h.compose('up', '-d', '--no-build', '--wait', '--no-deps', 'web', timeout=180)
            h.compose('cp', str(snapshot) + '/.', 'web:/var/www/html/rra')
            h.compose('exec', '-T', 'web', 'chown', '-R', 'www-data:www-data', '/var/www/html/rra')
            require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, 'Rollback did not restore snapshot')
            require(h.sql('SELECT cacti FROM version').strip() == evidence['steps']['baseline']['version'], 'Rollback version mismatch')
            authenticate(h)
            evidence['steps']['rollback'] = {'graph': assert_graph(h), 'plugin': assert_plugin(h),
                                             'poller': assert_poll(h, 'Rollback poller'),
                                             'snapshot_restored': True}
            evidence['complete'] = True
    except Exception as error:
        evidence['error'] = str(error)
        print(error, file=sys.stderr)
    finally:
        # The temporary compose tree can be gone after an exception. Project
        # containers are removed directly using their dedicated project label.
        try:
            cleanup = harness.run(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project], check=False)
            ids = cleanup['stdout'].split()
            if ids:
                harness.run(['docker', 'rm', '-fv', *ids], check=False)
            harness.run(['docker', 'network', 'rm', project + '_default'], check=False)
            harness.run(['docker', 'image', 'rm', project + '-baseline:local', project + '-candidate:local'], check=False)
        except Exception as error:
            evidence['cleanup_error'] = str(error)
            evidence['complete'] = False
        if h is not None:
            evidence['baseline_observations'] = h.observed
        harness.write_json(output / 'observations.json', evidence)
    print(json.dumps({'complete': evidence['complete'], 'output': str(output)}))
    return 0 if evidence['complete'] else 1


if __name__ == '__main__':
    sys.exit(main())
