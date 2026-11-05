<?php
/**
 * Local Composer stand-in: no dependency downloads or external commands.
 *
 * @author Brooklyn Fränzschky
 */
$manifest = json_decode(file_get_contents('composer.json'), true, 512, JSON_THROW_ON_ERROR);
$mode = $manifest['extra']['test_mode'] ?? 'success';
$home = getenv('COMPOSER_HOME');
$auth = json_decode(file_get_contents($home . DIRECTORY_SEPARATOR . 'auth.json'), true);
$secret = $auth['http-basic']['example.invalid']['password'] ?? '';
if ($secret === '') {
    fwrite(STDERR, 'Authentication was not supplied.');
    exit(4);
}
echo 'Sensitive Composer diagnostic: ' . $secret;
if ($mode === 'fail') {
    exit(7);
}
if ($mode === 'timeout') {
    sleep(4);
}
if ($mode === 'missing') {
    exit(0);
}
mkdir('vendor' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'library', 0777, true);
file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'autoload.php', '<?php');
file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'LICENSE', 'Fixture MIT license text');
file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"example/library","license":"MIT"}');
file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'runtime.txt', PHP_VERSION . PHP_EOL . PHP_BINARY);
if ($mode === 'credential') {
    file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'auth.json', json_encode($auth));
}
if ($mode === 'hiddencredential') {
    file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'readme.txt', str_repeat('x', 65530) . $secret);
}
$package = [
    'name' => 'example/library',
    'version' => $manifest['require']['example/library'] ?? '1.0.0',
    'license' => ['MIT']
];
if ($mode === 'lockcredential') {
    $package['dist'] = ['url' => 'https://user:' . $secret . '@example.invalid/package.zip'];
}
mkdir('vendor' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'dev');
file_put_contents('vendor' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'LICENSE', 'Dev dependency terms');
file_put_contents('composer.lock', json_encode([
    'packages' => [$package],
    'packages-dev' => [['name' => 'example/dev', 'version' => '1.0.0', 'license' => ['MIT']]]
]));
