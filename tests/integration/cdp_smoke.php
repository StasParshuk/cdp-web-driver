<?php

declare(strict_types=1);

use Facebook\WebDriver\Cdp\CdpClient;
use Facebook\WebDriver\Cdp\CdpCommandExecutor;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\WebDriverCommand;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Facebook\\WebDriver\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$host = getenv('CDP_HOST') ?: '127.0.0.1';
$port = (int) (getenv('CDP_PORT') ?: 9222);

$context = stream_context_create(['http' => ['header' => "Host: 127.0.0.1:9222\r\n", 'timeout' => 10]]);
$version = json_decode(
    (string) file_get_contents(sprintf('http://%s:%d/json/version', $host, $port), false, $context),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$client = new CdpClient($version['webSocketDebuggerUrl'], 30.0, $host, $port);
$executor = new CdpCommandExecutor($client);

// Bring up a session the same way RemoteWebDriver::create would.
$capabilities = $executor->execute(new WebDriverCommand(null, 'newSession', []))->getValue();

$driver = RemoteWebDriver::createByCommandExecutor(
    $executor,
    'cdp-session',
    new DesiredCapabilities($capabilities),
    true,
);

$failures = 0;
$check = static function (string $label, mixed $actual, mixed $expected = true) use (&$failures): void {
    $ok = $expected === true ? (bool) $actual : $actual === $expected;
    printf("  %-34s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $ok ? '' : sprintf(' (got %s)', var_export($actual, true)));
    if (!$ok) {
        ++$failures;
    }
};

echo "Browser: {$capabilities['browserVersion']}\n\n";

echo "navigation & page state\n";
$driver->get('https://example.com');
$check('getCurrentURL', $driver->getCurrentURL(), 'https://example.com/');
$check('getTitle', $driver->getTitle(), 'Example Domain');
$check('getPageSource contains h1', str_contains($driver->getPageSource(), '<h1>'));

echo "\nscripts\n";
$check('executeScript scalar', $driver->executeScript('return 6 * 7;'), 42);
$check('executeScript with args', $driver->executeScript('return arguments[0] + arguments[1];', [20, 22]), 42);
$check('executeAsyncScript', $driver->executeAsyncScript('var done = arguments[0]; setTimeout(function () { done("async-ok"); }, 50);'), 'async-ok');

echo "\nelements\n";
$heading = $driver->findElement(WebDriverBy::cssSelector('h1'));
$check('findElement getText', $heading->getText(), 'Example Domain');
$check('getTagName', $heading->getTagName(), 'h1');
$check('isDisplayed', $heading->isDisplayed());
$check('findElements count', count($driver->findElements(WebDriverBy::cssSelector('a'))) > 0);
$check('getAttribute', $driver->findElement(WebDriverBy::cssSelector('a'))->getAttribute('href') !== null);
$check('element size', $heading->getSize()->getWidth() > 0);

echo "\nscript returning an element\n";
$viaScript = $driver->executeScript('return document.querySelector("h1");');
$check('element round-trip', $viaScript instanceof Facebook\WebDriver\Remote\RemoteWebElement);
$check('element usable after round-trip', $viaScript->getText(), 'Example Domain');

echo "\nwaits (unmodified WebDriverWait)\n";
$driver->wait(5)->until(WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('h1')));
$check('WebDriverWait presence', true);

echo "\ninteraction\n";
$driver->findElement(WebDriverBy::cssSelector('a'))->click();
$driver->wait(10)->until(static fn ($d): bool => !str_contains($d->getCurrentURL(), 'example.com'));
$check('click navigated away', !str_contains($driver->getCurrentURL(), 'example.com'));

echo "\nforms\n";
$driver->get('https://www.google.com');
$box = $driver->findElement(WebDriverBy::cssSelector('textarea[name="q"], input[name="q"]'));
$box->sendKeys('cdp transport');
$check('sendKeys typed text', $driver->executeScript('return document.querySelector(\'textarea[name="q"], input[name="q"]\').value;'), 'cdp transport');
$box->clear();
$check('clear emptied input', $driver->executeScript('return document.querySelector(\'textarea[name="q"], input[name="q"]\').value;'), '');

echo "\nwindows & screenshot\n";
$check('getWindowHandles', count($driver->getWindowHandles()) >= 1);
$check('screenshot bytes', strlen((string) $driver->takeScreenshot()) > 1000);

echo "\nxpath\n";
$driver->get('https://example.com');
$check('xpath absolute', $driver->findElement(WebDriverBy::xpath('//h1'))->getText(), 'Example Domain');
$check('xpath findElements', count($driver->findElements(WebDriverBy::xpath('//a'))) > 0);
// Shapes the bot actually uses: ancestor::, local-name(), union, substring-before().
$check('xpath ancestor axis', $driver->findElement(WebDriverBy::xpath('//h1/ancestor::body'))->getTagName(), 'body');
$check('xpath union', count($driver->findElements(WebDriverBy::xpath('//h1 | //a'))) >= 2);
$check('xpath substring-before', count($driver->findElements(WebDriverBy::xpath('//a[substring-before(@href, ":") = "https"]'))) > 0);
$check('xpath local-name', count($driver->findElements(WebDriverBy::xpath('//*[local-name() = "h1"]'))) === 1);
// Relative xpath resolved against a context element.
$body = $driver->findElement(WebDriverBy::cssSelector('body'));
$check('xpath relative in element', $body->findElement(WebDriverBy::xpath('.//h1'))->getText(), 'Example Domain');
$check('xpath no match throws', (static function () use ($driver): bool {
    try {
        $driver->findElement(WebDriverBy::xpath('//nonexistent-tag'));

        return false;
    } catch (Facebook\WebDriver\Exception\NoSuchElementException) {
        return true;
    }
})());

echo "\nperformance log\n";
$driver->get('https://example.com');
$logs = $driver->manage()->getLog('performance');
$check('log entries collected', count($logs) > 0);
$hasRequest = false;
foreach ($logs as $entry) {
    $message = json_decode((string) $entry['message'], true, 512, JSON_THROW_ON_ERROR);
    if (($message['message']['method'] ?? '') === 'Network.requestWillBeSent') {
        $hasRequest = $hasRequest || ($message['message']['params']['request']['url'] ?? '') !== '';
    }
}
$check('requestWillBeSent with url', $hasRequest);

echo "\nfile upload\n";
$upload = tempnam(sys_get_temp_dir(), 'cdp') . '.txt';
file_put_contents($upload, 'cdp upload test');
$driver->executeScript('document.body.innerHTML = \'<input type="file" id="f">\';');
$fileInput = $driver->findElement(WebDriverBy::cssSelector('#f'));
$fileInput->setFileDetector(new Facebook\WebDriver\Remote\LocalFileDetector());
$fileInput->sendKeys($upload);
$check('file name set on input', $driver->executeScript('return document.querySelector("#f").files[0]?.name ?? "";') !== '');
unlink($upload);

echo "\nstealth injection (Page.addScriptToEvaluateOnNewDocument)\n";
// Exactly how the bot ships its evasions - through a raw CDP custom command.
$driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
    'cmd' => 'Page.addScriptToEvaluateOnNewDocument',
    'params' => ['source' => "Object.defineProperty(navigator, 'webdriver', { get: () => undefined });"],
]);

echo "\nDETECTION\n";
$driver->get('https://example.com');
$leaked = $driver->executeScript(<<<'JS'
    let leaked = false;
    const original = Error.prepareStackTrace;
    Error.prepareStackTrace = function () { leaked = true; return ''; };
    console.log(new Error(''));
    Error.prepareStackTrace = original;
    return leaked;
JS);
$check('Error.prepareStackTrace not called', $leaked, false);
$check('navigator.webdriver undefined', $driver->executeScript('return navigator.webdriver;'), null);

$driver->quit();

printf("\n%s\n", $failures === 0 ? 'ALL CHECKS PASSED' : sprintf('%d CHECK(S) FAILED', $failures));
exit($failures === 0 ? 0 : 1);
