# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Verify server-authenticated Basic login through the isolated Apache stack."""
import base64
import secrets

from harness import Session


def verify_basic_authentication(harness, check):
    username = 'basic-flow-' + secrets.token_hex(5)
    password = 'BasicFixture7!'

    def literal(value):
        return '0x' + str(value).encode().hex() if str(value) else "''"

    def scalar(sql):
        return harness.sql(sql).strip()

    original_auth_method = scalar("SELECT HEX(value) FROM settings WHERE name='auth_method'")
    account_id = None
    apache_enabled = False
    probe_path = '/auth-basic-probe-' + secrets.token_hex(5) + '.php'
    try:
        encoded_password = harness.php(
            '-r', 'echo password_hash($argv[1], PASSWORD_BCRYPT);', password
        )['stdout'].strip()
        harness.sql(
            'INSERT INTO user_auth(username,password,realm,enabled,locked,password_change,'
            'must_change_password,login_opts,lastchange,lastlogin) VALUES('
            f'{literal(username)},{literal(encoded_password)},2,\'on\',\'\',\'on\',\'\',3,'
            'UNIX_TIMESTAMP(),UNIX_TIMESTAMP())'
        )
        account_id = int(scalar(f'SELECT id FROM user_auth WHERE username={literal(username)} AND realm=2'))
        harness.sql(f'INSERT INTO user_auth_realm(user_id,realm_id) VALUES({account_id},8)')

        harness.sql("UPDATE settings SET value='2' WHERE name='auth_method'")
        anonymous = Session(harness.base)
        anonymous.request('/index.php')
        before_cookie = next(
            item.value for item in next(handler.cookiejar for handler in anonymous.opener.handlers
                                        if hasattr(handler, 'cookiejar')) if item.name == 'Cacti'
        )

        user_file = '/tmp/behavior-auth-users'
        config_file = '/etc/apache2/conf-available/behavior-auth.conf'
        harness.compose(
            'exec', '-T', 'web', 'sh', '-c',
            f'cat > {user_file} && chmod 0644 {user_file}',
            data=username + ':' + encoded_password + '\n',
        )
        harness.compose(
            'exec', '-T', 'web', 'sh', '-c', f'cat > {config_file}',
            data=(
                '<Location />\n'
                '  AuthType Basic\n'
                '  AuthName "Behavior authentication"\n'
                '  AuthBasicProvider file\n'
                f'  AuthUserFile {user_file}\n'
                '  Require valid-user\n'
                '</Location>\n'
                '<IfModule proxy_fcgi_module>\n'
                '  ProxyFCGISetEnvIf "true" REMOTE_USER "%{reqenv:REMOTE_USER}"\n'
                '</IfModule>\n'
            ),
        )
        harness.compose(
            'exec', '-T', 'web', 'sh', '-c',
            'a2enmod auth_basic authn_file && a2enconf behavior-auth && apache2ctl -k graceful',
        )
        apache_enabled = True

        harness.compose(
            'exec', '-T', 'web', 'sh', '-c',
            'cat > /var/www/html' + probe_path + ' && chown www-data:www-data /var/www/html' + probe_path,
            data=(
                '<?php header("Content-Type: application/json"); '
                'print json_encode(["remote_user" => $_SERVER["REMOTE_USER"] ?? null]);\n'
            ),
        )

        rejected = Session(harness.base)
        rejected.opener.addheaders = [
            ('Authorization', 'Basic ' + base64.b64encode((username + ':wrong').encode()).decode())
        ]
        check(rejected.request('/index.php')['status'] == 401,
              'Apache rejects an invalid Basic password before application authentication')
        rejected_cookies = next(handler.cookiejar for handler in rejected.opener.handlers
                                if hasattr(handler, 'cookiejar'))
        check(not any(item.name == 'Cacti' for item in rejected_cookies),
              'failed server Basic authentication cannot create an application session')

        anonymous.opener.addheaders = [
            ('Authorization', 'Basic ' + base64.b64encode((username + ':' + password).encode()).decode())
        ]
        server_identity = anonymous.request(probe_path)
        check(server_identity.get('json') == {'remote_user': username},
              'Apache forwards only its authenticated REMOTE_USER to PHP')
        admitted = anonymous.request('/index.php')
        check(admitted['status'] == 200 and not admitted['login_form'],
              'Apache-authenticated Basic identity enters through the native application path; '
              f"status={admitted['status']} title={admitted['title']!r} login_form={admitted['login_form']}")
        actor = anonymous.request('/app.php/session')
        check(actor.get('json') == {'id': account_id, 'username': username},
              'Basic login creates an identity accepted by the Symfony firewall')
        cookies = next(handler.cookiejar for handler in anonymous.opener.handlers
                        if hasattr(handler, 'cookiejar'))
        after_cookie = next(item.value for item in cookies if item.name == 'Cacti')
        check(after_cookie != before_cookie, 'Basic login rotates the existing anonymous session identifier')
    finally:
        if apache_enabled:
            harness.compose(
                'exec', '-T', 'web', 'sh', '-c',
                'a2disconf behavior-auth && rm -f /etc/apache2/conf-available/behavior-auth.conf '
                '/tmp/behavior-auth-users /var/www/html' + probe_path
                + ' && apache2ctl -k graceful',
            )
        if original_auth_method:
            harness.sql(
                "UPDATE settings SET value=CONVERT(0x" + original_auth_method
                + " USING utf8mb4) WHERE name='auth_method'"
            )
        if account_id is not None:
            for table, column in (
                ('user_auth_cache', 'user_id'), ('sessions', 'user_id'),
                ('settings_user', 'user_id'), ('user_auth_realm', 'user_id'),
                ('user_log', 'user_id'), ('user_auth', 'id'),
            ):
                harness.sql(f'DELETE FROM {table} WHERE {column}={account_id}')
