<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

function native_plugin_contract_run(array $command, array $environment): array
{
    $process = proc_open($command, [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, array_merge(getenv(), $environment));
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status' => proc_close($process),'output' => $output,'error' => $error];
}
function native_plugin_contract_cleanup(string $root): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

test('rendered plugin actions and INFO status use the same compatibility contract and directory identity', function ($compat, $admitted) {
    $root = sys_get_temp_dir().'/plugin-native-'.bin2hex(random_bytes(8));
    mkdir($root.'/plugins/native_fixture', 0700, true);
    if ($compat !== null) {
        file_put_contents($root.'/plugins/native_fixture/INFO', "[info]\nname = native_fixture\n".$compat."\n");
    }
    try {
        $result = native_plugin_contract_run([PHP_BINARY,dirname(__DIR__, 2).'/fixtures/plugin_install_native_probe.php'], ['PLUGIN_NATIVE_ROOT' => $root,'PLUGIN_NATIVE_MODE' => 'ui']);
        expect($result['status'])->toBe(0, $result['error'].$result['output'])->and($result['error'])->toBe('');
        $state = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        $document = new DOMDocument();
        expect($document->loadHTML('<table><tr>'.$state['html'].'</tr></table>'))->toBeTrue();
        $links = (new DOMXPath($document))->query('//a[contains(concat(" ",normalize-space(@class)," ")," piinstall ")]');
        expect($links->length)->toBe($admitted ? 1 : 0);
        if ($admitted) {
            expect($links->item(0)->getAttribute('href'))->toBe('/cacti/plugins.php?mode=install&id=native_fixture');
            expect($state['info']['status'])->toBe(0);
        } else {
            expect($state['html'])->toContain('pierror')->not->toContain('mode=install');
            $reason = $compat === null ? 'Legacy Plugin' : ($compat === 'compat = 99.0.0' ? 'Requires: Cacti &gt;= 99.0.0' : 'Plugin INFO must declare a valid compat version');
            expect($state['html'])->toContain($reason);
            if (is_array($state['info'])) {
                expect($state['info']['status'])->toBe(-1);
            }
        }
    } finally {
        native_plugin_contract_cleanup($root);
    }
})->with([
    ['compat = 1.2',true],['compat = 1.2.31',true],['compat = " 1.2.31 "',true],
    ['compat = 99.0.0',false],['compat = 1.bad',false],['compat[] = 1.2',false],['',false],[null,false],
]);

test('native CLI installs compatible callbacks and grants each plugin realm idempotently to the configured administrator', function () {
    $application = dirname(__DIR__, 3);
    $root = sys_get_temp_dir().'/plugin-native-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    foreach (['native_fixture','second_fixture'] as $plugin) {
        mkdir($root.'/plugins/'.$plugin, 0700, true);
        file_put_contents($root.'/plugins/'.$plugin.'/INFO', "[info]\nname = $plugin\ncompat = 1.2.31\n");
        $setup = '<?php function plugin_'.$plugin.'_version(){return ["longname"=>"Fixture","author"=>"Fixture","version"=>"1.0"];}'
            .'function plugin_'.$plugin.'_install(){file_put_contents('.var_export($root.'/callbacks', true).','.var_export($plugin."\n", true).',FILE_APPEND);api_plugin_register_realm('.var_export($plugin, true).',"fixture.php","Fixture",false);}';
        file_put_contents($root.'/plugins/'.$plugin.'/setup.php', $setup);
    }
    $source = file_get_contents($application.'/cli/plugin_manage.php');
    $require = "require(__DIR__ . '/../include/cli_check.php');";
    expect($source)->toContain($require);
    file_put_contents($root.'/cli.php', str_replace($require, 'require '.var_export(dirname(__DIR__, 2).'/fixtures/plugin_install_native_probe.php', true).';', $source));
    try {
        $command = [PHP_BINARY,$root.'/cli.php','--plugin=native_fixture','--plugin=second_fixture','--install','--allperms'];
        $environment = ['PLUGIN_NATIVE_ROOT' => $root,'PLUGIN_NATIVE_MODE' => 'cli'];
        $result = native_plugin_contract_run($command, $environment);
        expect($result['status'])->toBe(0, $result['error'].$result['output'])->and($result['error'])->toBe('');
        expect(substr_count($result['output'], 'installed successfully'))->toBe(2);
        expect(file_get_contents($root.'/callbacks'))->toBe("native_fixture\nsecond_fixture\n");
        $database = new PDO('sqlite:'.$root.'/database.sqlite');
        expect($database->query('SELECT directory,status FROM plugin_config ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe([
            ['directory' => 'native_fixture','status' => 4],['directory' => 'second_fixture','status' => 4],
        ]);
        $grants = $database->query('SELECT * FROM user_auth_realm ORDER BY realm_id')->fetchAll(PDO::FETCH_ASSOC);
        expect($grants)->toBe([['user_id' => 7,'realm_id' => 101],['user_id' => 7,'realm_id' => 102]]);
        $result = native_plugin_contract_run($command, $environment);
        expect($result['status'])->toBe(0, $result['error'].$result['output']);
        expect($database->query('SELECT * FROM user_auth_realm ORDER BY realm_id')->fetchAll(PDO::FETCH_ASSOC))->toBe($grants);
        expect(file_get_contents($root.'/callbacks'))->toBe("native_fixture\nsecond_fixture\n");
        foreach (['broken_fixture','later_fixture'] as $plugin) {
            mkdir($root.'/plugins/'.$plugin, 0700, true);
            file_put_contents($root.'/plugins/'.$plugin.'/INFO', "[info]\nname = $plugin\ncompat = 1.2.31\n");
            $setup = '<?php function plugin_'.$plugin.'_version(){return ["longname"=>"Fixture","author"=>"Fixture","version"=>"1.0"];}';
            if ($plugin === 'later_fixture') {
                $setup .= 'function plugin_later_fixture_install(){file_put_contents('.var_export($root.'/callbacks', true).',"later_fixture\n",FILE_APPEND);api_plugin_register_realm("later_fixture","fixture.php","Fixture",false);}';
            }
            file_put_contents($root.'/plugins/'.$plugin.'/setup.php', $setup);
        }
        $result = native_plugin_contract_run([PHP_BINARY,$root.'/cli.php','--plugin=broken_fixture','--plugin=absent_fixture','--plugin=later_fixture','--install','--allperms'], $environment);
        expect($result['status'])->toBe(1, $result['error'].$result['output'])->and($result['error'])->toBe('');
        expect($result['output'])->toContain("ERROR: Plugin 'broken_fixture' installation failed")
            ->toContain("ERROR: Plugin 'absent_fixture' missing plugin directory")
            ->toContain('Plugin later_fixture installed successfully');
        expect($database->query("SELECT COUNT(*) FROM plugin_realms WHERE plugin IN ('broken_fixture','absent_fixture')")->fetchColumn())->toBe(0);
        expect($database->query('SELECT * FROM user_auth_realm ORDER BY realm_id')->fetchAll(PDO::FETCH_ASSOC))->toBe([
            ['user_id' => 7,'realm_id' => 101],['user_id' => 7,'realm_id' => 102],['user_id' => 7,'realm_id' => 103],
        ]);
        expect(file_get_contents($root.'/callbacks'))->toBe("native_fixture\nsecond_fixture\nlater_fixture\n");
    } finally {
        native_plugin_contract_cleanup($root);
    }
});

test('native web install rejects incompatible metadata with a redirect before setup or plugin persistence', function () {
    $root = sys_get_temp_dir().'/plugin-web-'.bin2hex(random_bytes(8));
    mkdir($root.'/plugins/native_fixture', 0700, true);
    file_put_contents($root.'/plugins/native_fixture/INFO', "[info]\nname=native_fixture\ncompat=99.0.0\n");
    file_put_contents($root.'/plugins/native_fixture/setup.php', '<?php file_put_contents('.var_export($root.'/setup-ran', true).',"ran");');
    $router = '<?php require '.var_export(dirname(__DIR__, 2).'/fixtures/plugin_install_native_probe.php', true).';'
        .'register_shutdown_function(function(){file_put_contents(getenv("PLUGIN_NATIVE_ROOT")."/messages",json_encode($_SESSION["messages"]??[]));});'
        .'api_plugin_install("native_fixture");';
    file_put_contents($root.'/router.php', $router);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect(is_resource($listener))->toBeTrue();
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $process = null;
    try {
        $process = proc_open([PHP_BINARY,'-S',$address,$root.'/router.php'], [0 => ['pipe','r'],1 => ['file',$root.'/server.log','a'],2 => ['file',$root.'/server.log','a']], $pipes, null, array_merge(getenv(), ['PLUGIN_NATIVE_ROOT' => $root,'PLUGIN_NATIVE_MODE' => 'web']));
        expect(is_resource($process))->toBeTrue();
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0;$attempt < 100;$attempt++) {
            $socket = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        expect($ready)->toBeTrue();
        $context = stream_context_create(['http' => ['follow_location' => 0,'ignore_errors' => true,'timeout' => 5]]);
        $body = file_get_contents('http://'.$address.'/install', false, $context);
        expect($body)->toBe('')->and($http_response_header[0])->toContain('302');
        expect($http_response_header)->toContain('Location: plugins.php');
        $messages = json_decode(file_get_contents($root.'/messages'), true, 512, JSON_THROW_ON_ERROR);
        expect($messages[0][0])->toBe('dependency_check')->and($messages[0][1])->toContain('Requires: Cacti >= 99.0.0');
        expect(file_exists($root.'/setup-ran'))->toBeFalse();
        $database = new PDO('sqlite:'.$root.'/database.sqlite');
        expect($database->query('SELECT COUNT(*) FROM plugin_config')->fetchColumn())->toBe(0);
        expect(file_get_contents($root.'/server.log'))->not->toContain('Fatal error')->not->toContain('Warning:');
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        native_plugin_contract_cleanup($root);
    }
});
