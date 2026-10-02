"""Run the actual CLI upgrader against an installed LTS-version database."""
from pathlib import Path
from types import SimpleNamespace
import argparse
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', default='kadupul-schema-cli-upgrade')
    args = parser.parse_args()
    rig = Harness(SimpleNamespace(project=args.project, target='schema-cli-upgrade'))
    try:
        rig.setup()
        # Fresh application infrastructure remains real. Reproduce the two
        # scoped LTS table differences and its installed version before invoking
        # the shipped CLI, without replacing its bootstrap or version check.
        rig.sql("ALTER TABLE data_template_rrd DROP INDEX data_input_field_id;"
                "ALTER TABLE settings_user MODIFY user_id smallint(8) unsigned NOT NULL default '0';"
                "UPDATE version SET cacti='1.2.32';")
        result = rig.php('cli/upgrade_database.php', '--debug')
        if result['exit'] != 0:
            raise AssertionError(result['stdout'] + result['stderr'])
        version = rig.sql('SELECT cacti FROM version').strip()
        target = (Path(__file__).resolve().parents[2] / 'include/cacti_version').read_text().strip()
        if version != target or version == '1.2.32':
            raise AssertionError('Actual CLI failed to advance installed LTS 1.2.32: ' + result['stdout'])
        index = rig.sql("SELECT COUNT(*) FROM information_schema.STATISTICS "
                        "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='data_template_rrd' AND INDEX_NAME='data_input_field_id'").strip()
        if index != '1':
            raise AssertionError('Actual CLI did not install the reference index.')
        for user_id in (65536, 16777215):
            rig.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({user_id},'auth_credential_generation','fixture-generation');")
            value = rig.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='auth_credential_generation'").strip()
            if value != 'fixture-generation':
                raise AssertionError('Actual CLI did not preserve the full account ID range.')
        print(f'PASS actual CLI upgraded installed LTS 1.2.32 to {version}, indexed references and stored full-range user metadata.', flush=True)
    finally:
        if rig.setup_started:
            rig.compose('down', '--volumes', '--remove-orphans', timeout=120)
        rig.lock.close()


if __name__ == '__main__':
    main()
