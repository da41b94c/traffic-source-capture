<?php

declare(strict_types=1);

use Da41b94c\TrafficSource\TrafficSourceCapture;

$autoload = __DIR__.'/../vendor/autoload.php';
if (file_exists($autoload)) {
	require $autoload;
} else {
	require __DIR__.'/../src/TrafficSourceCapture.php';
}

final class TestRunner
{
	private $Passed = 0;
	private $Failed = 0;

	public function Run(string $Name, callable $Fn): void
	{
		try {
			$this->ResetState();
			$Fn();
			$this->Passed++;
			$this->Out("✅ ".$Name);
		} catch (\Throwable $e) {
			$this->Failed++;
			$this->Out("❌ ".$Name);
			$this->Out("   ".$e->getMessage());
		}
	}

	public function Summary(): int
	{
		$this->Out("");
		$this->Out("Passed: ".$this->Passed);
		$this->Out("Failed: ".$this->Failed);

		return $this->Failed === 0 ? 0 : 1;
	}

	private function Out(string $Line): void
	{
		fwrite(STDOUT, $Line.PHP_EOL);
	}

	private function ResetState(): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}

		$_SESSION = [];

		// Clean cookies used by the lib
		unset($_COOKIE[TrafficSourceCapture::CookieKey]);

		// Ensure stored data cleared
		TrafficSourceCapture::Clear(false);
	}
}

function AssertTrue($Cond, string $Msg = 'Assertion failed'): void
{
	if (!$Cond) {
		throw new \RuntimeException($Msg);
	}
}

function AssertSame($Expected, $Actual, string $Msg = ''): void
{
	if ($Expected !== $Actual) {
		$e = var_export($Expected, true);
		$a = var_export($Actual, true);
		throw new \RuntimeException(($Msg ? $Msg.' — ' : '')."Expected {$e}, got {$a}");
	}
}

function AssertContains(string $Needle, string $Haystack, string $Msg = ''): void
{
	if (strpos($Haystack, $Needle) === false) {
		throw new \RuntimeException(($Msg ? $Msg.' — ' : '')."Did not find '{$Needle}' in '{$Haystack}'");
	}
}

function GetChannel(): string
{
	$data = TrafficSourceCapture::GetData();
	return isset($data['Channel']) ? (string)$data['Channel'] : '';
}

$T = new TestRunner();

/* ---------------- Tests ---------------- */

$T->Run('AI via strict UTM (chatgpt + ai_chat)', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/landing?x=1',
	], [
		'utm_source' => 'chatgpt',
		'utm_medium' => 'ai_chat',
		'utm_campaign' => 'leadgen'
	], false, 0);

	AssertSame('ai', GetChannel());

	$human = TrafficSourceCapture::GetHuman();
	AssertContains('ИИ-чат', $human);
	AssertContains('UTM:', $human);
	AssertContains('utm_source=chatgpt', $human);

	$data = TrafficSourceCapture::GetData();
	AssertSame('/landing', $data['Landing'], 'Landing should not contain query string');
});

$T->Run('Paid via UTM (non-AI)', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
	], [
		'utm_source' => 'yandex',
		'utm_medium' => 'cpc',
		'utm_campaign' => '123'
	], false, 0);

	AssertSame('paid', GetChannel());
	AssertContains('Реклама', TrafficSourceCapture::GetHuman());
});

$T->Run('Paid via click-id only (gclid)', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
	], [
		'gclid' => 'test123'
	], false, 0);

	AssertSame('paid', GetChannel());
	AssertContains('gclid=test123', TrafficSourceCapture::GetHuman());
});

$T->Run('Direct when no referrer and no UTM', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
	], [], false, 0);

	AssertSame('direct', GetChannel());
	AssertContains('Прямой', TrafficSourceCapture::GetHuman());
});

$T->Run('Direct when referrer is internal', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
		'HTTP_REFERER' => 'https://example.com/any/page'
	], [], false, 0);

	AssertSame('direct', GetChannel());
});

$T->Run('Organic from Google with query extraction', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
		'HTTP_REFERER' => 'https://www.google.com/search?q=hello+world'
	], [], false, 0);

	AssertSame('organic', GetChannel());

	$human = TrafficSourceCapture::GetHuman();
	AssertContains('SEO (поиск)', $human);
	AssertContains('Google', $human);
	AssertContains('Запрос: hello world', $human);

	$data = TrafficSourceCapture::GetData();
	AssertSame('Google', $data['Search']['Engine']);
	AssertSame('hello world', $data['Search']['Query']);
});

$T->Run('Organic from Yandex with Cyrillic query extraction', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
		'HTTP_REFERER' => 'https://yandex.ru/search/?text=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82'
	], [], false, 0);

	AssertSame('organic', GetChannel());

	$data = TrafficSourceCapture::GetData();
	AssertSame('Yandex', $data['Search']['Engine']);
	AssertSame('привет', $data['Search']['Query']);
});

$T->Run('Referral from external site', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
		'HTTP_REFERER' => 'https://example.org/some/page'
	], [], false, 0);

	AssertSame('referral', GetChannel());

	$data = TrafficSourceCapture::GetData();
	AssertSame('example.org', $data['SourceHost']);
});

$T->Run('AI fallback via referrer host (chat.openai.com)', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
		'HTTP_REFERER' => 'https://chat.openai.com/share/abc'
	], [], false, 0);

	AssertSame('ai', GetChannel());
	AssertContains('ИИ-чат', TrafficSourceCapture::GetHuman());
});

$T->Run('Sanitization removes dangerous characters from UTM', function () {
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p',
	], [
		'utm_source' => 'yandex',
		'utm_medium' => 'cpc',
		'utm_campaign' => '<script>alert(1)</script>'
	], false, 0);

	$data = TrafficSourceCapture::GetData();
	AssertSame('paid', $data['Channel']);

	$utm = $data['Utm'];
	AssertTrue(strpos($utm['utm_campaign'], '<') === false, 'utm_campaign should not contain "<"');
	AssertTrue(strpos($utm['utm_campaign'], '>') === false, 'utm_campaign should not contain ">"');
});

$T->Run('TTL overwrite: expired first-touch can be replaced', function () {
	// Simulate old stored data
	$_SESSION[TrafficSourceCapture::SessionKey] = [
		'Channel' => 'direct',
		'FirstSeen' => time() - 9999,
		'Landing' => '/old',
		'SourceHost' => 'example.com',
	];

	// TTL=10 seconds → should overwrite with paid
	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/new',
	], [
		'utm_source' => 'yandex',
		'utm_medium' => 'cpc',
	], false, 10);

	AssertSame('paid', GetChannel());
});

$T->Run('Cookie restore: session empty but cookie contains data', function () {
	$payload = [
		'Channel' => 'referral',
		'FirstSeen' => time() - 5,
		'Landing' => '/x',
		'Referrer' => 'https://example.net/',
		'SourceHost' => 'example.net',
	];

	$_COOKIE[TrafficSourceCapture::CookieKey] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	TrafficSourceCapture::Capture([
		'HTTP_HOST' => 'example.com',
		'REQUEST_URI' => '/p'
	], [], true, 0);

	AssertSame('referral', GetChannel());
	$data = TrafficSourceCapture::GetData();
	AssertSame('example.net', $data['SourceHost']);
});

exit($T->Summary());
