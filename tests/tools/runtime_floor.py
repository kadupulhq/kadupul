"""Exercise the real runtime preflight and both bootstraps through mise PHP."""
import argparse
from http.client import HTTPConnection
from pathlib import Path
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
MESSAGE = b'Kadupul main requires PHP 8.4 or later.'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php-version', required=True)
    args = parser.parse_args()
    php = ['mise', 'exec', 'php@' + args.php_version, '--', 'php']
    version = subprocess.check_output(php + ['-r', 'echo PHP_VERSION;'], cwd=ROOT, text=True).strip()
    if not version.startswith(args.php_version):
        raise AssertionError(f'Requested PHP {args.php_version}, got {version}')
    supported = tuple(int(part) for part in version.split('.')[:2]) >= (8, 4)
    entries = ['include/runtime.php'] if supported else ['include/runtime.php', 'include/global.php', 'config/bootstrap.php']
    for entry in entries:
        result = subprocess.run(php + ['-r', 'require $argv[1];', str(ROOT / entry)],
                                cwd=ROOT, capture_output=True, timeout=30)
        expected = 0 if supported else 1
        if result.returncode != expected or result.stdout or result.stderr.strip() != (b'' if supported else MESSAGE):
            raise AssertionError(f'{entry}: unexpected CLI result {result.returncode}: {result.stdout!r} {result.stderr!r}')
        print(f'PASS PHP {version}: CLI {entry}', flush=True)
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    with tempfile.TemporaryFile() as server_log:
        server = subprocess.Popen(php + ['-S', f'127.0.0.1:{port}', '-t', str(ROOT)],
                                  cwd=ROOT, stdout=server_log, stderr=server_log)
        try:
            for entry in entries:
                deadline = time.monotonic() + 10
                while True:
                    connection = HTTPConnection('127.0.0.1', port, timeout=2)
                    try:
                        connection.request('GET', '/' + entry)
                        response = connection.getresponse()
                    except OSError:
                        connection.close()
                        if server.poll() is not None or time.monotonic() >= deadline:
                            raise
                        time.sleep(0.1)
                        continue
                    break
                try:
                    body = response.read()
                    if response.status != (200 if supported else 500) or body != (b'' if supported else MESSAGE):
                        raise AssertionError(f'{entry}: unexpected HTTP result {response.status}: {body!r}')
                    if not supported and response.headers.get_content_type() != 'text/plain':
                        raise AssertionError('Unsupported runtime must return plain text')
                finally:
                    connection.close()
                print(f'PASS PHP {version}: HTTP {entry}', flush=True)
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)


if __name__ == '__main__':
    main()
