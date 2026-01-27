<?php

namespace Da41b94c\TrafficSource;

final class TrafficSourceCapture
{
	const SessionKey = 'TrafficSourceCapture';
	const CookieKey = 'TrafficSourceCapture';
	const CookieTtl = 2592000; // 30 days

	private static $UtmKeys = [
		'utm_source','utm_medium','utm_campaign','utm_content','utm_term',
		'yclid','gclid','fbclid'
	];

	// AI: whitelist источников + whitelist medium (анти-подмена)
	private static $AiUtmSources = [
		'chatgpt','openai','gpt','claude','gemini','copilot','perplexity'
	];

	private static $AiUtmMediums = [
		'ai_chat'
	];

	// AI fallback по referrer (может не всегда приходить)
	private static $AiRefHosts = [
		'chat.openai.com',
		'chatgpt.com',
		'openai.com',
		'claude.ai',
		'gemini.google.com',
		'copilot.microsoft.com',
		'perplexity.ai'
	];

	// Поисковики
	private static $SearchEngines = [
		[
			'Name' => 'Yandex',
			'HostContains' => ['yandex.ru','ya.ru'],
			'QueryParams' => ['text','query']
		],
		[
			'Name' => 'Google',
			'HostContains' => ['google.'],
			'QueryParams' => ['q']
		],
		[
			'Name' => 'Bing',
			'HostContains' => ['bing.com'],
			'QueryParams' => ['q']
		],
		[
			'Name' => 'Mail.ru',
			'HostContains' => ['go.mail.ru','mail.ru'],
			'QueryParams' => ['q','query']
		],
		[
			'Name' => 'DuckDuckGo',
			'HostContains' => ['duckduckgo.com'],
			'QueryParams' => ['q']
		],
	];

	/**
	 * Захват first-touch.
	 * $useCookieBackup=true: восстановит из cookie, если сессия пустая, и сохранит в cookie при фиксации.
	 * $ttlSeconds: если задан и first-touch старше TTL — перезапишет (опционально).
	 */
	public static function Capture(array $server = null, array $get = null, $useCookieBackup = true, $ttlSeconds = 0)
	{
		self::EnsureSession();

		$server = is_array($server) ? $server : $_SERVER;
		$get = is_array($get) ? $get : $_GET;

		if ($useCookieBackup) {
			self::RestoreFromCookieIfNeeded();
		}

		if (self::HasData()) {
			if ($ttlSeconds > 0 && self::IsExpired($ttlSeconds)) {
				self::Clear(false); // чистим только сессию
			} else {
				return;
			}
		}

		$now = time();

		$currentHost = self::GetCurrentHost($server);
		$landing = self::GetSafeLanding($server);

		// 1) UTM (достаём один раз)
		$utm = self::ExtractUtm($get);

		// 1.1) AI chats: строго по UTM
		if (!empty($utm) && self::IsAiByUtmStrict($utm)) {
			self::Save([
				'Channel' => 'ai',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'Utm' => $utm,
				'SourceHost' => $currentHost,
			], $useCookieBackup);
			return;
		}

		// 1.2) Paid: любая UTM/click-id метка
		if (!empty($utm)) {
			self::Save([
				'Channel' => 'paid',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'Utm' => $utm,
				'SourceHost' => $currentHost,
			], $useCookieBackup);
			return;
		}

		// 2) Referrer
		$ref = isset($server['HTTP_REFERER']) ? self::CleanUrl($server['HTTP_REFERER'], 500) : '';
		if ($ref === '') {
			self::Save([
				'Channel' => 'direct',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'SourceHost' => $currentHost,
			], $useCookieBackup);
			return;
		}

		$refHost = self::GetHostFromUrl($ref);

		// внутренний / кривой referrer → direct
		if ($refHost === '' || $refHost === $currentHost) {
			self::Save([
				'Channel' => 'direct',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'Referrer' => $ref,
				'SourceHost' => $currentHost,
			], $useCookieBackup);
			return;
		}

		// 2.5) AI fallback по рефереру (когда UTM нет)
		if (self::IsAiByReferrerHost($refHost)) {
			self::Save([
				'Channel' => 'ai',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'Referrer' => $ref,
				'SourceHost' => $refHost,
			], $useCookieBackup);
			return;
		}

		// 3) Organic
		$search = self::DetectSearchEngine($refHost, $ref);
		if (!empty($search)) {
			self::Save([
				'Channel' => 'organic',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'Referrer' => $ref,
				'SourceHost' => $refHost,
				'Search' => $search,
			], $useCookieBackup);
			return;
		}

		// 4) Referral
		self::Save([
			'Channel' => 'referral',
			'FirstSeen' => $now,
			'Landing' => $landing,
			'Referrer' => $ref,
			'SourceHost' => $refHost,
		], $useCookieBackup);
	}

	public static function HasData()
	{
		self::EnsureSession();
		return !empty($_SESSION[self::SessionKey]['Channel']);
	}

	public static function GetData()
	{
		self::EnsureSession();
		return (isset($_SESSION[self::SessionKey]) && is_array($_SESSION[self::SessionKey]))
			? $_SESSION[self::SessionKey]
			: [];
	}

	public static function GetHuman()
	{
		$data = self::GetData();
		if (empty($data['Channel'])) {
			return 'Не определён';
		}

		$channel = (string)$data['Channel'];

		$channelHuman = [
			'ai' => 'ИИ-чат',
			'paid' => 'Реклама',
			'organic' => 'SEO (поиск)',
			'referral' => 'Переход с сайта',
			'direct' => 'Прямой',
		];

		$title = isset($channelHuman[$channel]) ? $channelHuman[$channel] : ucfirst($channel);

		$details = [];

		if ($channel === 'ai' || $channel === 'paid') {
			$utm = (isset($data['Utm']) && is_array($data['Utm'])) ? $data['Utm'] : [];
			if (!empty($utm)) {
				$details[] = 'UTM: '.self::BuildQuery($utm);
			}
		}

		if ($channel === 'organic') {
			$search = (isset($data['Search']) && is_array($data['Search'])) ? $data['Search'] : [];
			if (!empty($search['Engine'])) {
				$details[] = (string)$search['Engine'];
			}
			if (!empty($search['Query'])) {
				$details[] = 'Запрос: '.(string)$search['Query'];
			}
		}

		if ($channel === 'referral') {
			if (!empty($data['SourceHost'])) {
				$details[] = (string)$data['SourceHost'];
			}
		}

		if (!empty($data['Landing'])) {
			$details[] = 'Landing: '.(string)$data['Landing'];
		}

		return $title.(count($details) ? ' — '.implode('; ', $details) : '');
	}

	public static function GetLeadData()
	{
		$data = self::GetData();
		if (empty($data)) {
			return [
				'traffic_channel' => '',
				'traffic_source' => '',
				'traffic_details' => '',
				'traffic_raw' => '',
			];
		}

		$channel = isset($data['Channel']) ? (string)$data['Channel'] : '';
		$source = '';

		if ($channel === 'ai' || $channel === 'paid') {
			$utm = (isset($data['Utm']) && is_array($data['Utm'])) ? $data['Utm'] : [];
			$source = isset($utm['utm_source']) ? (string)$utm['utm_source'] : '';
		} elseif ($channel === 'organic') {
			$search = (isset($data['Search']) && is_array($data['Search'])) ? $data['Search'] : [];
			$source = isset($search['Engine']) ? (string)$search['Engine'] : '';
		} elseif ($channel === 'referral') {
			$source = isset($data['SourceHost']) ? (string)$data['SourceHost'] : '';
		} elseif ($channel === 'direct') {
			$source = 'direct';
		}

		return [
			'traffic_channel' => $channel,
			'traffic_source' => $source,
			'traffic_details' => self::GetHuman(),
			'traffic_raw' => self::ToJson($data),
		];
	}

	/**
	 * $clearCookie=true: удаляет и cookie backup тоже
	 */
	public static function Clear($clearCookie = true)
	{
		self::EnsureSession();
		unset($_SESSION[self::SessionKey]);

		if ($clearCookie) {
			self::SetCookie(self::CookieKey, '', time() - 3600);
		}
	}

	/* ---------------- internals ---------------- */

	private static function Save(array $data, $useCookieBackup)
	{
		$_SESSION[self::SessionKey] = $data;

		if ($useCookieBackup) {
			$json = self::ToJson($data);
			if ($json !== '') {
				self::SetCookie(self::CookieKey, $json, time() + self::CookieTtl);
			}
		}
	}

	private static function RestoreFromCookieIfNeeded()
	{
		if (self::HasData()) {
			return;
		}

		if (empty($_COOKIE[self::CookieKey]) || !is_string($_COOKIE[self::CookieKey])) {
			return;
		}

		$raw = $_COOKIE[self::CookieKey];
		if (strlen($raw) > 3000) {
			return;
		}

		$data = json_decode($raw, true);
		if (!is_array($data) || empty($data['Channel'])) {
			return;
		}

		$channel = (string)$data['Channel'];
		$allowed = ['ai','paid','organic','referral','direct'];
		if (!in_array($channel, $allowed, true)) {
			return;
		}

		$_SESSION[self::SessionKey] = $data;
	}

	private static function IsExpired($ttlSeconds)
	{
		$data = self::GetData();
		if (empty($data['FirstSeen'])) {
			return false;
		}
		return (time() - (int)$data['FirstSeen']) > (int)$ttlSeconds;
	}

	private static function EnsureSession()
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
	}

	private static function SetCookie($name, $value, $expire, $path = '/', $sameSite = 'Lax')
	{
		$secure = self::IsHttps();
		$httpOnly = true;

		// PHP 7.3+ умеет options array, но нам нужно 7.0+ → делаем совместимо
		if (PHP_VERSION_ID >= 70300) {
			setcookie($name, $value, [
				'expires' => (int)$expire,
				'path' => $path,
				'secure' => $secure,
				'httponly' => $httpOnly,
				'samesite' => $sameSite
			]);
			return;
		}

		// Fallback для PHP 7.0–7.2: SameSite через "path"
		$pathWithSameSite = $path.'; samesite='.$sameSite;
		setcookie($name, $value, (int)$expire, $pathWithSameSite, '', $secure, $httpOnly);
	}

	private static function IsHttps()
	{
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
			return true;
		}
		if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
			return true;
		}
		return false;
	}

	private static function GetSafeLanding(array $server)
	{
		$uri = isset($server['REQUEST_URI']) ? (string)$server['REQUEST_URI'] : '';
		$uri = self::CleanText($uri, 300);

		// Убираем query целиком: UTM сохраняем отдельно
		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$uri = substr($uri, 0, $pos);
		}

		return $uri;
	}

	private static function ExtractUtm(array $get)
	{
		$out = [];

		foreach (self::$UtmKeys as $k) {
			if (!isset($get[$k])) {
				continue;
			}

			$v = self::CleanText((string)$get[$k], 150);
			if ($v !== '') {
				$out[$k] = $v;
			}
		}

		return $out;
	}

	private static function IsAiByUtmStrict(array $utm)
	{
		$src = isset($utm['utm_source']) ? strtolower((string)$utm['utm_source']) : '';
		$med = isset($utm['utm_medium']) ? strtolower((string)$utm['utm_medium']) : '';

		if ($src === '' || $med === '') {
			return false;
		}

		if (!in_array($src, self::$AiUtmSources, true)) {
			return false;
		}

		if (!in_array($med, self::$AiUtmMediums, true)) {
			return false;
		}

		return true;
	}

	private static function IsAiByReferrerHost($refHost)
	{
		foreach (self::$AiRefHosts as $h) {
			if ($refHost === $h) {
				return true;
			}
		}
		return false;
	}

	private static function GetCurrentHost(array $server)
	{
		$host = isset($server['HTTP_HOST']) ? (string)$server['HTTP_HOST'] : '';
		return self::NormalizeHost($host);
	}

	private static function GetHostFromUrl($url)
	{
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return '';
		}
		return self::NormalizeHost($host);
	}

	private static function NormalizeHost($host)
	{
		$host = strtolower(trim($host));
		$host = preg_replace('#:\d+$#', '', $host);
		$host = preg_replace('#^www\.#i', '', $host);

		if (function_exists('idn_to_ascii')) {
			$ascii = @idn_to_ascii($host, 0, defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 0);
			if (is_string($ascii) && $ascii !== '') {
				$host = $ascii;
			}
		}

		$host = preg_replace('/[^\p{L}\p{N}\.\-]/u', '', $host);
		return $host;
	}

	private static function DetectSearchEngine($refHost, $refUrl)
	{
		foreach (self::$SearchEngines as $se) {
			if (!self::HostMatches($refHost, $se['HostContains'])) {
				continue;
			}

			$query = self::ExtractSearchQuery($refUrl, $se['QueryParams']);

			return [
				'Engine' => $se['Name'],
				'Host' => $refHost,
				'Query' => $query,
			];
		}

		return [];
	}

	private static function HostMatches($host, array $needles)
	{
		foreach ($needles as $n) {
			if (stripos($host, $n) !== false) {
				return true;
			}
		}
		return false;
	}

	private static function ExtractSearchQuery($url, array $paramNames)
	{
		$queryString = parse_url($url, PHP_URL_QUERY);
		if (!is_string($queryString) || $queryString === '') {
			return '';
		}

		parse_str($queryString, $params);
		if (!is_array($params)) {
			return '';
		}

		foreach ($paramNames as $p) {
			if (!isset($params[$p])) {
				continue;
			}

			$q = $params[$p];
			if (is_array($q)) {
				$q = reset($q);
			}

			$q = self::CleanText((string)$q, 160);
			if ($q !== '') {
				return $q;
			}
		}

		return '';
	}

	private static function CleanText($value, $maxLen)
	{
		$value = trim($value);

		if (function_exists('mb_substr')) {
			$value = mb_substr($value, 0, (int)$maxLen, 'UTF-8');
		} else {
			$value = substr($value, 0, (int)$maxLen);
		}

		$value = preg_replace('/\p{Cc}+/u', '', $value);

		// Разрешаем: буквы/цифры/пробел/._-/%/+/=
		$value = preg_replace('/[^\p{L}\p{N}\s\-\._%+=]/u', '', $value);

		return $value === null ? '' : $value;
	}

	private static function CleanUrl($value, $maxLen)
	{
		$value = trim($value);

		if (function_exists('mb_substr')) {
			$value = mb_substr($value, 0, (int)$maxLen, 'UTF-8');
		} else {
			$value = substr($value, 0, (int)$maxLen);
		}

		$value = preg_replace('/\p{Cc}+/u', '', $value);
		$value = preg_replace('/[<>"\']+/u', '', $value);

		return $value === null ? '' : $value;
	}

	private static function BuildQuery(array $data)
	{
		$pairs = [];
		foreach ($data as $k => $v) {
			$pairs[] = $k.'='.$v;
		}
		return implode(', ', $pairs);
	}

	private static function ToJson(array $data)
	{
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return is_string($json) ? $json : '';
	}
}
