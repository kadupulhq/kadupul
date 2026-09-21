"""Exercise the repository-root Nginx boundary and CLI guards over real HTTP."""
import argparse
from http.client import HTTPConnection, HTTPException
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]


def run(*args):
    return subprocess.check_output(args, text=True).strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php-image', default='php:8.4-fpm')
    args = parser.parse_args()
    name = 'kadupul-private-' + uuid.uuid4().hex[:12]
    php, nginx = name + '-php', name + '-nginx'
    with tempfile.TemporaryDirectory(prefix=name) as directory:
        stage = Path(directory)
        stage.chmod(0o755)
        private = ['src/Kernel.php', 'config/services.yaml', 'templates/base.html.twig',
                   'var/cache/private.php', 'log/cacti.log', 'rra/private.rrd', 'tests/private.php',
                   'cli/private.php', 'scripts/private.php', 'contrib/private.php', 'mibs/private.txt',
                   'docs/private.md', 'lib/private.php', '.env', '.git/config',
                   'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
                   'phpunit-symfony.xml', 'mise.toml', 'include/config.php',
                   'include/vendor/private.php', 'cache/private.php']
        for relative in private:
            path = stage / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('PRIVATE-CONTENT')
        tools = ['tools/dependencies/install-legacy.php', 'tools/verify-offline.php',
                 'tools/migrate/assess.php', 'bin/legacy-device-edit.php', 'bin/console']
        guards = []
        for index, relative in enumerate(tools):
            path = stage / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, path)
            # Public aliases deliberately bypass directory denies to test PHP's
            # own CLI guard without any database or dependency installation.
            alias = f'guard-{index}.php'
            shutil.copyfile(path, stage / alias)
            guards.append((alias, path.read_text().startswith('#!')))
        for relative in ['index.php', 'app.php', 'public/index.php']:
            path = stage / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('<?php echo "PUBLIC:" . ($_SERVER["PATH_INFO"] ?? "");')
        assets = ['include/js/public.js', 'include/themes/public.css', 'include/vendor/csrf/csrf-magic.js',
                  'include/vendor/flag-icons/flags/4x3/us.svg']
        for relative in assets:
            path = stage / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('PUBLIC-ASSET')
        run('docker', 'network', 'create', name)
        try:
            run('docker', 'run', '-d', '--name', php, '--network', name, '--network-alias', 'php',
                '--entrypoint', 'php-fpm', '--volume', f'{stage}:/var/www/html/cacti:ro', args.php_image, '-F')
            run('docker', 'run', '-d', '--name', nginx, '--network', name, '-p', '127.0.0.1::80',
                '--volume', f'{stage}:/var/www/html/cacti:ro',
                '--volume', f'{ROOT}/tests/e2e/nginx.conf:/etc/nginx/conf.d/default.conf:ro', 'nginx:alpine')
            port = run('docker', 'port', nginx, '80/tcp').rsplit(':', 1)[1]

            def request(path):
                connection = HTTPConnection('127.0.0.1', int(port), timeout=5)
                try:
                    connection.request('GET', '/' + path)
                    with connection.getresponse() as response:
                        return response.status, response.read().decode()
                finally:
                    connection.close()

            for attempt in range(30):
                try:
                    if request('index.php') == (200, 'PUBLIC:'):
                        break
                except (HTTPException, OSError):
                    pass
                time.sleep(0.2)
            else:
                raise RuntimeError('Nginx/PHP fixture did not become ready')
            denied = private + tools + ['tools/dependencies/install-legacy.php/extra',
                       '%74ools/dependencies/install-legacy.php', 'config', 'src', 'var', 'tools',
                       'include/vendor/private.php/extra', 'does-not-exist.php']
            for path in denied:
                status, body = request(path)
                if status != 404 or 'PRIVATE-CONTENT' in body:
                    raise AssertionError(f'Private path exposed: {path} ({status})')
            for path, shebang in guards:
                status, body = request(path)
                # FPM with output buffering disabled may already have emitted
                # a CLI shebang. No bootstrap or tool output may follow it.
                if shebang:
                    if status not in (200, 404) or body != '#!/usr/bin/env php\n':
                        raise AssertionError('CLI shebang entry executed over HTTP: ' + path)
                elif status != 404 or body:
                    raise AssertionError('CLI entry executed over HTTP: ' + path)
            for path in assets:
                if request(path) != (200, 'PUBLIC-ASSET'):
                    raise AssertionError('Public asset blocked: ' + path)
            for path in ['app.php/healthz', 'public/index.php/healthz']:
                if request(path) != (200, 'PUBLIC:/healthz'):
                    raise AssertionError('Symfony PATH_INFO routing failed: ' + path)
            print(f'PASS {len(denied)} private/CLI paths; public assets and Symfony PATH_INFO', flush=True)
        finally:
            subprocess.run(['docker', 'rm', '-f', nginx, php], capture_output=True, check=False)
            subprocess.run(['docker', 'network', 'rm', name], capture_output=True, check=True)


if __name__ == '__main__':
    main()
