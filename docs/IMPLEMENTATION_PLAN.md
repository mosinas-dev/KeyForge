# IMPLEMENTATION_PLAN.md — KeyForge

План сборки для агента. Исполняется строго по фазам и по TDD. Детали алгоритма — `docs/AGENTIC_SPEC.md`, правила/каркас — `CLAUDE.md` (в корне).

## Расположение документации
Вся проектная документация (этот план, спека, ADR, runbook, формат экспорта) живёт в **`docs/`**. Единственное исключение — **`CLAUDE.md` в корне репо** (Claude Code грузит только `./CLAUDE.md`). Любой новый документ агент создаёт в `docs/`.

## Порядок чтения файлов (агенту)
1. `CLAUDE.md` (корень) — жёсткие правила, структура, принципы.
2. `docs/AGENTIC_SPEC.md` — алгоритм (§2), edge-кейсы (§11), реестры артефактов/skills/MCP, схема БД (§8).
3. `docs/IMPLEMENTATION_PLAN.md` (этот файл) — что и в каком порядке делать.

## Манифест передаваемых файлов
| Файл | Куда | Назначение |
|---|---|---|
| `CLAUDE.md` | корень репо | контекст, грузится каждый запуск |
| `docs/AGENTIC_SPEC.md` | `docs/` | спецификация |
| `docs/IMPLEMENTATION_PLAN.md` | `docs/` | план сборки |
| `Dockerfile`, `docker-compose.yml`, `.dockerignore`, `.env.example`, `Makefile` | корень | инфра, zero-touch запуск |
| `docker/nginx/default.conf`, `docker/php/{entrypoint.sh,opcache.ini,php.ini}` | `docker/` | конфиги |
| `.github/workflows/ci.yml` | `.github/workflows/` | CI: тесты → build → GHCR |
| `sample_data/*.csv` | `sample_data/` | грязные тестовые данные (4 источника) |

---

## Зафиксированные решения

1. **`project_id` — РЕШЕНО, закладываем сейчас (Фаза 1).** `project_id INT NOT NULL DEFAULT 1` (FK → `project`) на ВСЕХ tenant-scoped таблицах (`keyword`, `negative_keyword`, `ad_group`, `import_batch`, `config_*`); seed `project(id=1, name='Site.pro')`; **все составные индексы ведут с `project_id`** (`(project_id, ...)`). `UNIQUE(project_id, import_hash)`. Сама tenant-изоляция запросов, выбор/переключение проекта и UI — отложены (§13 spec); сейчас только колонка + FK + индексы + дефолтный проект.
2. **Forbidden-список и `language→url` карта — сидим заглушками.** `config_forbidden_term` и `config_language_url_map` наполняются seed-значениями (по sample-данным: ru/en/pt/es/de → `https://site.pro/<lang>`), редактируются в админке. Реальные значения от Борцов Гроуп заменяют seed позже — это не блокер.

---

## Принцип исполнения каждой фазы
- **TDD:** сначала падающие тесты по edge-кейсам (§11 spec) → показать red → реализация до green → refactor.
- **Коммит после каждой отдельной задачи.** Задача завершена (тесты зелёные) — сразу атомарный коммит с осмысленным сообщением (что и зачем), не копить изменения до конца фазы. Гранулярность — задача, а не фаза (например: `test(ingest): red на пустой файл/BOM/CP1251`, `feat(ingest): CsvSource → green`, `refactor: вынес KeywordNormalizer`). Шаги red → green → refactor одной задачи можно коммитить раздельно. Каждый коммит оставляет дерево в зелёном или явно red-состоянии (TDD-коммит «red» помечается в сообщении).
- **Гейт фазы (Definition of Done):** все тесты зелёные + self-review по `code-review` skill + ручной апрув миграций (если были).
- **SOLID/DRY/YAGNI** — по §12 spec, прагматично. Отложенное (§13) не трогать.
- Не переходить к следующей фазе, пока гейт текущей не пройден.

---

## Фаза 0 — Bootstrap (agent A1/A3)
**Цель:** пустой каркас Yii2 поднимается через `docker compose up -d`.
**Сделать:** `yii2-app-advanced` (composer create-project), `init`, конфиг БД на pgsql из `KEYFORGE_DB_*` env, Codeception (unit/integration/functional, pgsql-подключение для integration), `composer.json`/`composer.lock`. 
**Тесты-первыми:** smoke — приложение бутстрапится, healthcheck отвечает.
**Гейт:** `docker compose up -d` собирает образ (тест-гейт проходит на пустом unit-сьюте), `http://localhost:8080` отдаёт страницу backend, CI зелёный.

## Фаза 1 — Модель данных + миграции + seed (agent A2)
**Цель:** схема БД и конфиги по §8 spec.
**Сделать:** ADR по §1 spec. Миграции всех `kf_*` таблиц; `CREATE EXTENSION pg_trgm`; `project` + `project_id NOT NULL DEFAULT 1` (FK) на всех tenant-scoped таблицах, **составные индексы ведут с `project_id`**; `UNIQUE(project_id, import_hash)`; индексы под dedup/фильтры. Seed: `config_brand_term`, `config_forbidden_term`, `config_volume_threshold`, `config_language_url_map`, `project(id=1,'Site.pro')`, RBAC-роли.
**Тесты-первыми:** таблицы/индексы/extension существуют; `import_hash` уникален; seed загружен; повторный `migrate` идемпотентен.
**Гейт:** `yii migrate` чисто на чистой БД (через `up -d` авто), ручной апрув миграций получен.

## Фаза 2 — Источники + ingest (agent A4)
**Цель:** импорт CSV/JSON в канонную модель, идемпотентно.
**Сделать:** интерфейс `KeywordSourceProvider`; `CsvSource`, `JsonSource`; сервис `KeywordNormalizer`; `IngestStage`; `import_hash = sha256(source+raw)`; учёт в `import_batch`. Команда `yii keyforge/import <file>` (до ingest).
**Тесты-первыми (§11 Ingest):** пустой файл/только заголовок; CP1251 vs UTF-8; BOM; разделитель `;`/`,`; кавычки/запятые в значении; обрезанная строка; не-числовой/отрицательный объём; **повторный импорт того же файла → 0 новых**; разные файлы, одинаковый ключ → разные hash.
**Гейт:** импорт 4 `sample_data/*.csv` → строки в `kf_keyword`; ре-импорт = 0 новых.

## Фаза 3 — Конвейер чистки (agent A4)
**Цель:** стадии §2.2–2.6.
**Сделать:** `PipelineStage` (интерфейс), `PipelineRunner` (линейный, НЕ DAG), `PipelineContext`. Стадии: `JunkFilterStage` (→ `kf_negative_keyword`, не удалять), `LanguageDetectStage` (по тексту, фолбэк на источник), `IntentClassifyStage`, `FuzzyDedupStage` (`pg_trgm`, канон = макс. объём), `VolumeFilterStage` (адаптивный порог per-language).
**Тесты-первыми (§11):** junk → negatives; язык — смешанный/транслит/короткий→фолбэк/вне карты; интент — граничные маркеры; dedup — регистр/перестановка слов/диакритика/порог 0.85/детерминированный tie-break/разные языки не схлопывать; объём — язык из одной строки/все нули.
**Гейт:** на sample — junk (`asdkjh qwe`,`????`) в negatives; бренды отфильтрованы; `website builder`×3 и word-order схлопнуты в канон; языки проставлены.

## Фаза 4 — Подготовка к GAds (agent A4)
**Цель:** §2.7–2.8.
**Сделать:** `GadsPrepStage` — убрать used (`google_ads_keywords`) и forbidden; competitor gap (`ahrefs_paid` минус used → `is_opportunity`); STAG-группы по `(intent, language)`; `kf_ad_group` с `target_url` из `language→url`.
**Тесты-первыми (§11):** used побеждает opportunity; forbidden исключён; группа из одного ключа; пустые группы не создаются; группы строго моноязычны.
**Гейт:** группы из sample корректны, `target_url` соответствует языку.

## Фаза 5 — Генерация объявлений RSA (agent A5)
**Цель:** §2.9.
**Сделать:** интерфейс `AdCopyGenerator`; `LlmAdCopyGenerator`; `RsaLengthValidator`; `AdGenerationStage`. На группу: 15 заголовков (≤30) / 4 описания (≤90), на языке группы, URL из карты, брендовый заголовок пиннится; невалидные — перегенерация.
**Тесты-первыми (§11):** заголовок 30/31 (граница), описание 90/91; пустой/битый JSON от LLM → перегенерация, не падение; язык ответа ≠ язык группы → reject.
**Гейт:** валидный RSA на каждую группу, всё в лимитах символов.

## Фаза 5.5 — Рефактор архитектуры (§14/§15, «точечный»)
**Триггер:** ужесточение правил (CLAUDE §14/§15). Решение: точечный рефактор перед завершением Фаз 6–8.
**Сделать:**
- **Repository-слой** (порт + Pg-адаптер), весь SQL стадий → за интерфейсы: `KeywordRepositoryInterface`, `ConfigRepositoryInterface`, `AdGroupRepositoryInterface` (+ RSA), `NegativeKeywordRepositoryInterface`, `ImportBatchRepositoryInterface`. Стадии/сервисы зависят от интерфейсов, не от `Connection`/`Yii::$app` (§15.1/15.3/15.12). Биндинги — в `container.singletons`.
- **readonly DTO:** `AdCopy`, `AdCopyRequest`.
- **Result-объекты:** `RsaValidationResult` (вместо array/bool в `RsaLengthValidator`), `ExportResult`.
- **НЕ делаем (YAGNI):** value-objects на каждый примитив, тотальные коллекции, Clock/Uuid-порты (в домене нет рантайм-времени/uuid — время через `CURRENT_TIMESTAMP` в Pg-адаптере).
**Тесты:** существующие — green после рефактора (behavior-preserving); + unit на Result-объекты, integration на репозитории.
**Гейт:** весь сьют зелёный; ни одна стадия/сервис не содержит прямого SQL или `Yii::$app`.

## Фаза 6 — Экспорт (agent A4)
**Цель:** §2.10.
**Сделать:** интерфейс `CampaignExporter`; `GoogleAdsEditorExporter`; `ExportStage`; команда `yii keyforge/export`. Формат Google Ads Editor (§docs/gads_export_format.md); минус-слова экспортируются отдельно.
**Тесты-первыми (§11):** CSV-экранирование спецсимволов; пустой результат → валидный файл с заголовком; UTF-8/кириллица.
**Гейт:** файл открывается в Google Ads Editor без ошибок импорта.

## Фаза 7 — Админка + UI (agent A3)
**Цель:** деливерабл §5 ТЗ — upload, admin-area, preview, export.
**Сделать:** Gii-CRUD ревью ключей; форма загрузки (CSV/JSON); экран preview сгенерированных кампаний; кнопка экспорта-скачивания; RBAC. Тяжёлый импорт — синхронно (очередь отложена §13).
**Тесты-первыми:** functional/acceptance (Codeception) — upload → data видна → preview → export; e2e через Browser MCP.
**Гейт:** на `http://localhost:8080/admin` работают все 4: upload / admin-area / preview / export.

## Фаза 8 — E2E + сдача (agent A6/A8)
**Цель:** приёмка §9 spec + публикация.
**Сделать:** e2e-прогон 4 CSV → экспорт с ассертами §9; README/runbook (A8); CI зелёный; образ в GHCR.
**Гейт (финальный DoD):**
- `docker compose up -d` с нуля → рабочая админка без ручных шагов.
- Все ассерты §9 spec проходят (идемпотентность, junk→negatives, бренды/дубли вычищены, моноязычные группы, лимиты RSA 30/90, экспорт открывается в GAds Editor).
- `make test` (полный сьют на Postgres) зелёный.
- CI пушит образ в `ghcr.io/<owner>/<repo>:latest`.

---

## Карта фаза → деливерабл ТЗ
| ТЗ | Фаза |
|---|---|
| Тестовые данные (4 источника) | дано (`sample_data/`) |
| Импорт CSV/JSON | 2 |
| Админка, видимость данных | 7 |
| Чистка (junk/дубли/бренды/объём) | 3 |
| GAds-prep (used/forbidden/merge/группы/языки) | 4 |
| Генерация Ads (язык + URL, формат GAds) | 5 |
| Preview + файл экспорта | 6, 7 |
| Тестовый URL (upload/admin/preview/export) | 7, 8 → `localhost:8080` |

## Первый промпт агенту
> «Читай `CLAUDE.md` (корень) → `docs/AGENTIC_SPEC.md` → `docs/IMPLEMENTATION_PLAN.md`. Вся новая документация (ADR, runbook, формат экспорта) — в `docs/`. Исполняй по фазам 0→8 строго по TDD: на каждую задачу сперва падающие тесты по §11, покажи red, потом реализация до green, refactor. Решения по `project_id` и seed — приняты (см. «Зафиксированные решения»), не спрашивай повторно. Миграции на локальной БД авто-применяются через `docker compose up -d`; деструктив схемы — только с моим апрувом. После каждой фазы — гейт (зелёные тесты + self-review по `code-review`), и стоп для моего подтверждения перед следующей фазой.»
