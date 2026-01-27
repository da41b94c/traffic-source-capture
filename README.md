# TrafficSourceCapture

First-touch фиксация источника трафика (UTM / referrer / поисковики / AI-чаты) **без сторонних инструментов**.
Подходит, когда нужно быстро сохранить источник и приклеить его к заявке (CRM/почта/Telegram/БД).

## Что делает

На первом касании определяет канал и детали, сохраняет их в `$_SESSION` (и опционально в cookie как backup),
а позже позволяет получить человекочитаемую строку или структурированные данные для заявки.

Каналы:
- `ai` — ИИ-чаты (строго по UTM: `utm_source` из whitelist + `utm_medium=ai_chat`, есть fallback по referrer)
- `paid` — реклама (любые UTM / `gclid` / `yclid` / `fbclid`)
- `organic` — поиск (определение поисковика + попытка вытащить поисковый запрос)
- `referral` — переход с сайта (внешний referrer)
- `direct` — прямой заход (нет referrer / внутренний referrer)

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

## Что возвращает GetLeadData()

`GetLeadData()` возвращает массив:

- `traffic_channel` — `ai|paid|organic|referral|direct`
- `traffic_source` — краткий источник (например, utm_source или название поисковика)
- `traffic_details` — человекочитаемая строка (`GetHuman()`)
- `traffic_raw` — JSON со всеми сохранёнными данными

## Важно про безопасность

Данные источника — **недоверенные**:
- Любой может подделать UTM в URL.
- `HTTP_REFERER` может отсутствовать или быть урезанным.
- Нельзя использовать эти данные как механизм безопасности.

Рекомендации:
- Если выводите значения в админке — выводите как plain text или экранируйте (`htmlspecialchars`).
- Не полагайтесь на источник трафика для принятия “охранных” решений.

## Requirements

- PHP `>= 7.0`
- (опционально) `ext-intl` для `idn_to_ascii()` при нормализации IDN доменов

## License

MIT
