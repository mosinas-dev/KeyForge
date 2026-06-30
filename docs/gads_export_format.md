# Формат выгрузки Google Ads Editor

Документ описывает CSV-формат, который порождает `GoogleAdsEditorExporter` (§2.10).
Экспорт состоит из **двух файлов**: `campaigns.csv` (кампании/группы/ключи/объявления)
и `negatives.csv` (минус-слова). Кодировка — **UTF-8**; экранирование — RFC4180
(делается `league/csv`). Файлы импортируются в Google Ads Editor через
*Account → Import → From file*.

---

## 1. `campaigns.csv`

Одна «плоская» таблица: каждая строка — это либо **ключевое слово**, либо **объявление
(RSA)**. Тип строки Google Ads Editor определяет по заполненным колонкам.

### Колонки (в порядке следования)

| Колонка | Keyword-строка | Ad-строка (RSA) |
|---|---|---|
| `Campaign` | имя кампании | имя кампании |
| `Ad Group` | имя группы | имя группы |
| `Keyword` | текст ключа | *(пусто)* |
| `Match Type` | `Phrase` | *(пусто)* |
| `Final URL` | URL группы | URL группы |
| `Headline 1` … `Headline 15` | *(пусто)* | заголовки (≤30 симв.) |
| `Description 1` … `Description 4` | *(пусто)* | описания (≤90 симв.) |

- **Campaign** выводится из языка группы: `SP_<LANG_UPPER>` (напр. `SP_EN`, `SP_RU`).
- **Ad Group** = `<LANG_UPPER>_<intent>` (напр. `EN_commercial`).
- **Final URL** = `target_url` группы из карты `language → url` (`kf_config_language_url_map`).
- **Match Type** для ключей — `Phrase` (дефолт KeyForge; меняется при необходимости).
- На каждую группу: N keyword-строк + **одна** ad-строка с RSA. Если у группы нет
  валидного RSA (генерация не прошла валидацию длины/языка) — ad-строка **не пишется**,
  только keyword-строки (объявление маркетолог добавляет вручную).

### Пример

```csv
Campaign,Ad Group,Keyword,Match Type,Final URL,Headline 1,...,Headline 15,Description 1,...,Description 4
SP_EN,EN_commercial,website builder,Phrase,https://site.pro/en,,...,,,...,
SP_EN,EN_commercial,,,https://site.pro/en,Site.pro,Easy Website Builder,...,Build your site fast.,...
```

(первая строка — ключ, вторая — RSA с запиннеными/обычными заголовками)

### Пиннинг

RSA-заголовки хранятся в `kf_responsive_search_ad.headlines` как
`[{ "text": "...", "pin": 1|null }, ...]`. Брендовый заголовок (`Site.pro`) пиннится на
позицию 1 (`pin: 1`). В текущем CSV-экспорте выводится только `text`; колонки
позиции пиннинга Google Ads Editor можно добавить отдельным полем при необходимости
(заложено в модели данных, не разворачиваем без триггера — YAGNI).

---

## 2. `negatives.csv`

Минус-слова экспортируются **отдельным файлом** (junk → минус-слова, §2.2).

| Колонка | Значение |
|---|---|
| `Campaign` | *(пусто)* — общий/аккаунтный список минус-слов |
| `Keyword` | текст минус-слова (нормализованный) |
| `Match Type` | `Negative Phrase` |

```csv
Campaign,Keyword,Match Type
,????,Negative Phrase
,asdkjh qwe,Negative Phrase
```

Пустой `Campaign` означает общий (shared/account-level) negative-список.

---

## 3. Edge-кейсы (покрыты тестами §11)

- **Спецсимволы** (`,` `"` перевод строки) экранируются по RFC4180:
  `website, "free" builder` → `"website, ""free"" builder"`.
- **Пустой результат** (нет групп/минус-слов) → файл с **строкой-заголовком**, не пустые
  байты (Google Ads Editor не падает на импорте).
- **UTF-8/кириллица** сохраняются как есть (`конструктор сайтов`, `Создайте сайт`).

---

## 4. Команда

```bash
yii keyforge/export                    # пишет в @runtime/export (по умолчанию)
yii keyforge/export --outputDir=/path  # произвольный каталог
```

Запускать после `yii keyforge/prepare-gads`.
