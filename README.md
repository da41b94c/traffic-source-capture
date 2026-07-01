[![Packagist Version](https://img.shields.io/packagist/v/da41b94c/traffic-source-capture.svg)](https://packagist.org/packages/da41b94c/traffic-source-capture)
[![CI](https://github.com/da41b94c/traffic-source-capture/actions/workflows/ci.yml/badge.svg)](https://github.com/da41b94c/traffic-source-capture/actions/workflows/ci.yml)

# TrafficSourceCapture

First-touch фиксация источника трафика (UTM / click-id / referrer / поисковики / AI-чаты) **без сторонних инструментов**.
Подходит, когда нужно быстро сохранить источник и приклеить его к заявке (CRM/почта/Telegram/БД).

## Что делает

На первом касании определяет канал и детали, сохраняет их в `$_SESSION` (и опционально в cookie как backup),
а позже позволяет получить человекочитаемую строку или структурированные данные для заявки.

Каналы:
- `ai` — ИИ-чаты (строго по UTM: `utm_source` из whitelist + `utm_medium=ai_chat`, есть fallback по referrer)
- `paid` — реклама (UTM / click-id: `gclid`, `yclid`, `ysclid`, `gbraid`, `wbraid`, `msclkid` и др.)
- `organic` — поиск (определение поисковика + попытка вытащить поисковый запрос)
- `referral` — переход с сайта (внешний referrer)
- `direct` — прямой заход (нет referrer / внутренний referrer)

Дополнительно сохраняется `traffic_confidence`:
- `high` — строгий AI UTM или полный рекламный UTM (`utm_source` + `utm_medium` + `utm_campaign`)
- `medium` — click-id only, organic, referral, AI по referrer fallback
- `low` — direct

## Установка

```bash
composer require da41b94c/traffic-source-capture
```

## Быстрый старт

В начале запроса (до обработки форм):

```php
use Da41b94c\TrafficSource\TrafficSourceCapture;

TrafficSourceCapture::Capture();
```

В момент сохранения заявки:

```php
$TrafficText = TrafficSourceCapture::GetHuman();     // строка для уведомлений/CRM
$TrafficData = TrafficSourceCapture::GetLeadData();  // массив для БД/CRM
```

## Поддерживаемые tracking-параметры

Библиотека сохраняет:

- UTM: `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `utm_id`, `utm_source_platform`, `utm_creative_format`, `utm_marketing_tactic`
- click-id: `yclid`, `gclid`, `fbclid`, `ysclid`, `gbraid`, `wbraid`, `msclkid`, `ttclid`, `vkclid`, `roistat`, `_openstat`
- дополнительные короткие метки: `from`

## Пример UTM для AI-чата

Чтобы переход определился как `ai`, UTM должны соответствовать whitelist-логике:

- `utm_source=chatgpt`
- `utm_medium=ai_chat`

Пример:

`https://example.com/landing?utm_source=chatgpt&utm_medium=ai_chat&utm_campaign=leadgen`

Если UTM не проходят строгую проверку AI — они попадут в `paid`.

## Cookie backup и TTL

`Capture()` поддерживает cookie backup (включён по умолчанию) и TTL (опционально).

```php
// useCookieBackup=true, ttlSeconds=0 (по умолчанию)
TrafficSourceCapture::Capture(null, null, true, 0);

// Пример: разрешить перезапись first-touch через 30 дней
TrafficSourceCapture::Capture(null, null, true, 2592000);
```

При восстановлении из cookie данные повторно валидируются: неизвестные поля отбрасываются, строки очищаются, канал и confidence проверяются по whitelist.

## Что возвращает GetLeadData()

`GetLeadData()` возвращает массив. Старые поля сохранены для совместимости, новые добавлены поверх них:

```php
[
	'traffic_channel' => 'paid',
	'traffic_source' => 'yandex',
	'traffic_medium' => 'cpc',
	'traffic_campaign' => 'roofmaster',
	'traffic_content' => '',
	'traffic_term' => '',
	'traffic_landing' => '/landing',
	'traffic_referrer_host' => 'example.com',
	'traffic_confidence' => 'high',
	'traffic_click_ids' => '{"yclid":"123"}',
	'traffic_details' => 'Реклама — UTM: utm_source=yandex, utm_medium=cpc, utm_campaign=roofmaster; Landing: /landing',
	'traffic_raw' => '{...}'
]
```

Ключевые поля:

- `traffic_channel` — `ai|paid|organic|referral|direct`
- `traffic_source` — краткий источник (например, `utm_source`, название поисковика, домен referrer или имя click-id)
- `traffic_medium` — `utm_medium`
- `traffic_campaign` — `utm_campaign`
- `traffic_content` — `utm_content`
- `traffic_term` — `utm_term`
- `traffic_landing` — посадочная страница без query string
- `traffic_referrer_host` — домен источника/referrer или текущий host для direct/UTM-входа
- `traffic_confidence` — `high|medium|low`
- `traffic_click_ids` — JSON с click-id
- `traffic_details` — человекочитаемая строка (`GetHuman()`)
- `traffic_raw` — JSON со всеми сохранёнными данными

## Мини-пример для CRM / Bitrix24 / MODX

```php
$TrafficData = TrafficSourceCapture::GetLeadData();

$LeadFields = [
	'TITLE' => 'Заявка с сайта',
	'COMMENTS' => $TrafficData['traffic_details'],
	'UTM_SOURCE' => $TrafficData['traffic_source'],
	'UTM_MEDIUM' => $TrafficData['traffic_medium'],
	'UTM_CAMPAIGN' => $TrafficData['traffic_campaign'],
	'UTM_CONTENT' => $TrafficData['traffic_content'],
	'UTM_TERM' => $TrafficData['traffic_term'],
	'UF_TRAFFIC_CHANNEL' => $TrafficData['traffic_channel'],
	'UF_TRAFFIC_CONFIDENCE' => $TrafficData['traffic_confidence'],
	'UF_TRAFFIC_LANDING' => $TrafficData['traffic_landing'],
	'UF_TRAFFIC_RAW' => $TrafficData['traffic_raw'],
];
```

Если выводите эти данные в админке MODX/CRM, используйте plain text или экранирование:

```php
echo htmlspecialchars($TrafficData['traffic_details'], ENT_QUOTES, 'UTF-8');
```

## Важно про безопасность

Данные источника — **недоверенные**:
- Любой может подделать UTM в URL.
- `HTTP_REFERER` может отсутствовать или быть урезанным.
- Cookie backup может быть изменён пользователем, поэтому при восстановлении данные валидируются повторно.
- Нельзя использовать источник трафика как механизм безопасности, антифрода или контроля доступа.

Рекомендации:
- Если выводите значения в админке — выводите как plain text или экранируйте (`htmlspecialchars`).
- Не полагайтесь на источник трафика для принятия “охранных” решений.
- Для аналитики заявок храните `traffic_raw`, но показывайте менеджеру `traffic_details`.

## Requirements

- PHP `>= 7.0`
- (опционально) `ext-intl` для `idn_to_ascii()` при нормализации IDN доменов

## License

MIT
