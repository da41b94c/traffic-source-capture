<?php

namespace Da41b94c\TrafficSource;

final class TrafficSourceCapture
{
	const SessionKey = 'TrafficSourceCapture';
	const CookieKey = 'TrafficSourceCapture';
	const CookieTtl = 2592000; // 30 days
	const SchemaVersion = 2;

	private static $UtmKeys = [
		'utm_source','utm_medium','utm_campaign','utm_content','utm_term',
		'utm_id','utm_source_platform','utm_creative_format','utm_marketing_tactic',
		'yclid','gclid','fbclid','ysclid','gbraid','wbraid','msclkid','ttclid','vkclid',
		'roistat','_openstat','from'
	];

	private static $ClickIdKeys = [
		'yclid','gclid','fbclid','ysclid','gbraid','wbraid','msclkid','ttclid','vkclid','roistat','_openstat'
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

		// 1) UTM / click-id (достаём один раз)
		$utm = self::ExtractUtm($get);

		// 1.1) AI chats: строго по UTM
		if (!empty($utm) && self::IsAiByUtmStrict($utm)) {
			self::Save([
				'Channel' => 'ai',
				'FirstSeen' => $now,
				'Landing' => $landing,
				'Utm' => $utm,
				'SourceHost' => $currentHost,
				'Confidence' => 'high',
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
				'Confidence' => self::GetPaidConfidence($utm),
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
				'Confidence' => 'low',
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
				'Confidence' => 'low',
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
				'Confidence' => 'medium',
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
				'Confidence' => 'medium',
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
			'Confidence' => 'medium',
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
				'traffic_medium' => '',
				'traffic_campaign' => '',
				'traffic_content' => '',
				'traffic_term' => '',
				'traffic_landing' => '',
				'traffic_referrer_host' => '',
				'traffic_confidence' => '',
				'traffic_click_ids' => '',
				'traffic_details' => '',
				'traffic_raw' => '',
			];
		}

		$channel = isset($data['Channel']) ? (string)$data['Channel'] : '';
		$utm = (isset($data['Utm']) && is_array($data['Utm'])) ? $data['Utm'] : [];
		$search = (isset($data['Search']) && is_array($data['Search'])) ? $data['Search'] : [];
		$clickIds = self::ExtractClickIds($utm);
		$source = '';

		if ($channel === 'ai' || $channel === 'paid') {
			$source = isset($utm['utm_source']) ? (string)$utm['utm_source'] : '';
			if ($source === '' && !empty($clickIds)) {
				$keys = array_keys($clickIds);
				$source = isset($keys[0]) ? (string)$keys[0] : '';
			}
			if ($source === '' && $channel === 'ai' && !empty($data['SourceHost'])) {
				$source = (string)$data['SourceHost'];
			}
		} elseif ($channel === 'organic') {
			$source = isset($search['Engine']) ? (string)$search['Engine'] : '';
		} elseif ($channel === 'referral') {
			$source = isset($data['SourceHost']) ? (string)$data['SourceHost'] : '';
		} elseif ($channel === 'direct') {
			$source = 'direct';
		}

		return [
			'traffic_channel' => $channel,
			'traffic_source' => $source,
			'traffic_medium' => isset($utm['utm_medium']) ? (string)$utm['utm_medium'] : '',
			'traffic_campaign' => isset($utm['utm_campaign']) ? (string)$utm['utm_campaign'] : '',
			'traffic_content' => isset($utm['utm_content']) ? (string)$utm['utm_content'] : '',
			'traffic_term' => isset($utm['utm_term']) ? (string)$utm['utm_term'] : '',
			'traffic_landing' => isset($data['Landing']) ? (string)$data['Landing'] : '',
			'traffic_referrer_host' => isset($data['SourceHost']) ? (string)$data['SourceHost'] : '',
			'traffic_confidence' => isset($data['Confidence']) ? (string)$data['Confidence'] : '',
			'traffic_click_ids' => self::ToJson($clickIds),
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
		$data['SchemaVersion'] = self::SchemaVersion;
		$data = self::NormalizeStoredData($data);
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

		$data = self::NormalizeStoredData($data);
		if (empty($data['Channel'])) {
			return;
		}

		$_SESSION[self::SessionKey] = $data;
	}

	private static function NormalizeStoredData(array $data)
	{
		$channel = isset($data['Channel']) ? (string)$data['Channel'] : '';
		$allowedChannels = ['ai','paid','organic','referral','direct'];
		if (!in_array($channel, $allowedChannels, true)) {
			return [];
		}

		$out = [
			'SchemaVersion' => self::SchemaVersion,
			'Channel' => $channel,
		];

		if (isset($data['FirstSeen'])) {
			$out['FirstSeen'] = (int)$data['FirstSeen'];
		}

		if (!empty($data['Landing'])) {
			$landing = self::CleanPath((string)$data['Landing'], 300);
			if ($landing !== '') {
				$out['Landing'] = $landing;
			}
		}

		if (!empty($data['Utm']) && is_array($data['Utm'])) {
			$utm = self::ExtractUtm($data['Utm']);
			if (!empty($utm)) {
				$out['Utm'] = $utm;
			}
		}

		if (!empty($data['Referrer'])) {
			$referrer = self::CleanUrl((string)$data['Referrer'], 500);
			if ($referrer !== '') {
				$out['Referrer'] = $referrer;
			}
		}

		if (!empty($data['SourceHost'])) {
			$sourceHost = self::NormalizeHost((string)$data['SourceHost']);
			if ($sourceHost !== '') {
				$out['SourceHost'] = $sourceHost;
			}
		}

		if (!empty($data['Search']) && is_array($data['Search'])) {
			$search = [];
			if (!empty($data['Search']['Engine'])) {
				$search['Engine'] = self::CleanText((string)$data['Search']['Engine'], 50);
			}
			if (!empty($data['Search']['Host'])) {
				$search['Host'] = self::NormalizeHost((string)$data['Search']['Host']);
			}
			if (!empty($data['Search']['Query'])) {
				$search['Query'] = self::CleanText((string)$data['Search']['Query'], 160);
			}
			if (!empty($search)) {
				$out['Search'] = $search;
			}
		}

		if (!empty($data['Confidence']) && self::IsAllowedConfidence((string)$data['Confidence'])) {
			$out['Confidence'] = (string)$data['Confidence'];
		} else {
			$out['Confidence'] = self::GuessConfidence($out);
		}

		return $out;
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
		
		$pos = strpos($uri, '?');
		if ($pos !== false) {
			$uri = substr($uri, 0, $pos);
		}

		return self::CleanPath($uri, 300);
	}

	private static function ExtractUtm(array $get)
	{
		$out = [];

		foreach (self::$UtmKeys as $k) {
			if (!isset($get[$k])) {
				continue;
			}

			$v = $get[$k];
			if (is_array($v)) {
				$v = reset($v);
			}

			$v = self::CleanText((string)$v, 150);
			if ($v !== '') {
				$out[$k] = $v;
			}
		}

		return $out;
	}

	private static function ExtractClickIds(array $utm)
	{
		$out = [];

		foreach (self::$ClickIdKeys as $k) {
			if (!empty($utm[$k])) {
				$out[$k] = (string)$utm[$k];
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
		return self::HostMatches($refHost, self::$AiRefHosts);
	}

	private static function GetPaidConfidence(array $utm)
	{
		if (!empty($utm['utm_source']) && !empty($utm['utm_medium']) && !empty($utm['utm_campaign'])) {
			return 'high';
		}

		return 'medium';
	}

	private static function IsAllowedConfidence($confidence)
	{
		return in_array($confidence, ['high','medium','low'], true);
	}

	private static function GuessConfidence(array $data)
	{
		$channel = isset($data['Channel']) ? (string)$data['Channel'] : '';
		$utm = (isset($data['Utm']) && is_array($data['Utm'])) ? $data['Utm'] : [];

		if ($channel === 'direct') {
			return 'low';
		}

		if ($channel === 'paid') {
			return self::GetPaidConfidence($utm);
		}

		if ($channel === 'ai' && self::IsAiByUtmStrict($utm)) {
			return 'high';
		}

		return 'medium';
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
		return $host === null ? '' : $host;
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
		$host = strtolower((string)$host);
		if ($host === '') {
			return false;
		}

		foreach ($needles as $n) {
			$n = strtolower(trim((string)$n));
			if ($n === '') {
				continue;
			}

			// Family pattern: google. matches google.com and news.google.com, but not evilgoogle.com.
			if (substr($n, -1) === '.') {
				if (strpos($host, $n) === 0 || strpos($host, '.'.$n) !== false) {
					return true;
				}
				continue;
			}

			if ($host === $n) {
				return true;
			}

			$suffix = '.'.$n;
			if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
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
	
	private static function CleanPath($value, $maxLen)
	{
		$value = trim((string)$value);

		if (function_exists('mb_substr')) {
			$value = mb_substr($value, 0, (int)$maxLen, 'UTF-8');
		} else {
			$value = substr($value, 0, (int)$maxLen);
		}

		$value = preg_replace('/\p{Cc}+/u', '', $value);

		// Разрешаем: буквы/цифры/слэши/._-/%/+/=
		$value = preg_replace('/[^\p{L}\p{N}\/\-\._%+=]/u', '', $value);

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
