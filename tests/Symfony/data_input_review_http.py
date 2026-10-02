"""Run the data input contract against isolated HTTP and MariaDB."""
from pathlib import Path
from types import SimpleNamespace
import sys
import argparse
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'Support/Behavior'))
from harness import Harness, Session
from data_input_scenarios import verify_data_inputs

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--coverage-output',type=Path)
    parser.add_argument('--database-sessions',action='store_true')
    parser.add_argument('--project',default='kadupul-data-input-review')
    args=parser.parse_args()
    harness=Harness(SimpleNamespace(project=args.project,target=args.project))
    command=harness.command
    def installer_allowance(*arguments,check=False):
        if 'cli/install_cacti.php' in arguments:
            return harness.compose('exec','-T','-u','www-data','web',*arguments,check=check,timeout=600)
        return command(*arguments,check=check)
    harness.command=installer_allowance
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness,args.coverage_output)
    checks=[]
    def check(condition,message):
        if not condition: raise AssertionError(message)
        checks.append(message)
        print('PASS '+message,flush=True)
    try:
        harness.setup()
        if args.database_sessions:
            harness.compose('exec','-T','-u','root','web','php','-r',r'file_put_contents("include/config.php", "\n\$cacti_db_session = true;\n", FILE_APPEND);')
        session=Session(harness.base)
        check(not session.login('behavior-admin')['login_form'],'admin authenticates')
        verify_data_inputs(harness,session,check)
        if args.coverage_output:
            from coverage_support import publish_coverage
            publish_coverage(args.coverage_output,args.database_sessions,checks)
        print(f'Data input HTTP/MariaDB passed: {len(checks)} checks.',flush=True)
    finally:
        if harness.setup_started: harness.compose('down','--volumes','--remove-orphans',timeout=120)
        harness.lock.close()

if __name__=='__main__': main()
