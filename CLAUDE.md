# CLAUDE.md — KeyForge

Контекст проекта для Claude Code. Загружается каждый запуск. Детали — в `docs/AGENTIC_SPEC.md` и `docs/IMPLEMENTATION_PLAN.md` (не дублируем здесь). Вся проектная документация — в `docs/`; этот файл (`CLAUDE.md`) — единственный в корне, т.к. Claude Code грузит только `./CLAUDE.md`.

---

## Проект

**KeyForge** — платформа автоматизации работы маркетолога Site.pro: импорт ключевых слов из нескольких источников → чистка → группировка по языку/интенту → генерация Google Ads кампаний (RSA) → экспорт в формат Google Ads Editor.

**Боль, которую закрываем:** ручная чистка/группировка ключей и сборка кампаний. Цель — быстрее, проще, **предсказуемо**.

**Стек:** Yii2 (advanced template) · PostgreSQL · yii2-queue (отложено, §13 spec) · league/csv
**Метод сборки:** 0-code. Код пишут агенты. Человек = архитектор/ревьюер, руками код не пишет.

---

## ⛔ Жёсткие правила (не нарушать)

1. **TDD строго.** Ни строки production-кода без падающего теста. Порядок: red → green → refactor. На каждую стадию — тесты по edge-кейсам из §11 spec ПЕРВЫМИ. Показать red до реализации.
2. **`migrate` НЕ запускать без апрува человека** на shared/staging/prod. Исключение: **локальный `docker compose up -d`** авто-мигрирует одноразовую БД (zero-touch, явно разрешено). Деструктив схемы (`dropColumn`/`dropTable`) — всегда только после явного «да».
3. **Комментарии не удалять.** Даже при рефакторинге. Устаревший комментарий — обновить, не вырезать.
4. **Descriptive variable names** всегда. `$normalizedKeyword`, не `$k`. `$searchVolumeThreshold`, не `$t`. Имена сущностей и колонок — самодокументируемые.
5. **Не врать. Не знаешь — скажи «I don't know»** и спроси/исследуй. Никаких выдуманных API Yii2 или несуществующих методов.
6. **Не строить отложенное (§13 spec).** Outbox/Saga/DAG/async/multi-tenant/API-источники — только по триггеру. Это YAGNI-нарушение.
7. **Тесты на реальном Postgres** (Testcontainers), не sqlite-мок — иначе `pg_trgm`/advisory-локи не проверяются.

---

## Принципы разработки

**TDD** — тесты первыми, покрывают edge-кейсы (§11 spec), потом реализация. Пирамида: ~70% unit · ~25% integration (SQL на pgsql) · ~5% e2e.

**SOLID — прагматично, на швах, без фанатизма:**
- SRP — одна стадия делает одно (`JunkFilterStage` не детектит язык).
- OCP/LSP — новый источник/стадия/экспортёр = новый класс под интерфейсом, без правки старых.
- ISP/DIP — узкие раздельные интерфейсы (`KeywordSourceProvider`, `AdCopyGenerator`, `CampaignExporter`); `PipelineRunner` зависит от `PipelineStage`, а не от классов.
- НЕ тащим SOLID в ActiveRecord-модели, Gii-CRUD, DTO — там абстракции = оверхед.

**DRY** — нормализация ключа, валидация длины RSA, парсинг CSV — в одном месте. НО не склеивать похожий код стадий с разной причиной изменения (ложный DRY ломает SRP).

**YAGNI** — `import_hash` оставляем (дёшево, дорого ретрофитить). Всё из §13 spec — не заранее.

**Архитектура:** «порты сейчас, адаптеры потом». Интерфейс закладываем дёшево, реализацию — по триггеру.

---

## Структура проекта

```
keyforge/
├── CLAUDE.md                      # этот файл (ТОЛЬКО он в корне)
├── docs/                          # ВСЯ документация здесь
│   ├── AGENTIC_SPEC.md            # полный спец (алгоритм, реестры, edge-кейсы, схема)
│   ├── IMPLEMENTATION_PLAN.md     # план сборки по фазам
│   ├── adr/                       # ADR-решения
│   └── gads_export_format.md      # формат выгрузки Google Ads Editor
├── docker-compose.yml             # php-fpm + nginx + postgres (+ redis за профилем)
├── common/
│   ├── models/                    # ActiveRecord (Gii): Project, Keyword, AdGroup, ResponsiveSearchAd, ...
│   ├── pipeline/
│   │   ├── PipelineStage.php       # interface: run(PipelineContext): PipelineContext
│   │   ├── PipelineRunner.php      # линейный прогон упорядоченного списка стадий (НЕ DAG)
│   │   ├── PipelineContext.php
│   │   └── stages/                 # по классу на стадию §2 spec
│   │       ├── IngestStage.php
│   │       ├── JunkFilterStage.php
│   │       ├── LanguageDetectStage.php
│   │       ├── IntentClassifyStage.php
│   │       ├── FuzzyDedupStage.php
│   │       ├── VolumeFilterStage.php
│   │       ├── GadsPrepStage.php
│   │       ├── AdGenerationStage.php
│   │       └── ExportStage.php
│   ├── sources/                   # KeywordSourceProvider: CsvSource, JsonSource (API — отложено)
│   ├── export/                    # CampaignExporter: GoogleAdsEditorExporter
│   ├── adgen/                     # AdCopyGenerator: LlmAdCopyGenerator + RsaLengthValidator
│   └── services/                  # shared: KeywordNormalizer, ...
├── console/
│   ├── controllers/               # KeyforgeController: import / prepare-gads / export
│   └── migrations/                # схема + seed config_* (бренды, forbidden, пороги, language→url)
├── backend/                       # админка: RBAC, AdminLTE, CRUD-ревью ключей, preview, export
├── frontend/                      # минимальный upload-UI
├── config/                        # incl. gii.php
├── tests/
│   ├── unit/                      # чистые функции (нормализация, интент, валидатор RSA)
│   ├── integration/               # SQL-операции на Testcontainers pgsql (dedup, фильтры)
│   └── e2e/                       # 4 CSV → экспорт, ассерты §9 spec
└── sample_data/                   # 4 готовых CSV (грязные: дубли/мусор/бренды/мультиязык)
```

**Префикс таблиц БД:** `kf_` (`kf_keyword`, `kf_ad_group`, `kf_responsive_search_ad`, `kf_negative_keyword`, `kf_config_*`, `kf_import_batch`).

---

## Команды

```bash
docker-compose up -d                  # поднять окружение
yii migrate                           # схема + seed (ТОЛЬКО после апрува)
yii keyforge/import <file.csv|json>   # стадии ingest → volume-filter
yii keyforge/prepare-gads             # used/forbidden, merge, группы, RSA
yii keyforge/export                   # → файл Google Ads Editor
vendor/bin/codecept run               # все тесты
# Админка: http://localhost:8080/admin  (upload / data / preview / export)
```

**Docker/CI.** Локально — `docker compose up -d` (или `make up`): zero-touch с нуля — build → ждёт healthcheck БД → авто-migrate+seed → отдаёт админку на `:8080`. Полные тесты: `make test` (профиль `test`, unit+integration на реальном Postgres). Образ **multi-stage**: `base → vendor/vendor-dev → test(гейт) → runtime`; runtime не соберётся, если unit-тесты упали. CI (`.github/workflows/ci.yml`): тесты → build → push в **GHCR** (`ghcr.io/<owner>/<repo>:latest`). Redis (очередь) и frontend-UI — за профилями/закомментированы (YAGNI). Образ по умолчанию миграции не трогает (`RUN_MIGRATIONS`, opt-in).

---

## Логика чистки

SQL-first для множественных операций (dedup через `pg_trgm`, фильтры брендов/forbidden/объёма, идемпотентность через `import_hash UNIQUE`). PHP-слой — оркестрация, классификация язык/интент, LLM-генерация. Правила (бренды, forbidden, пороги, `language→url`) — данные в `kf_config_*`, НЕ хардкод.

Алгоритм по стадиям — §2 spec. Главные улучшения: junk → минус-слова (не удалять), детект языка по тексту (не доверять источнику), интент-фильтр, адаптивный порог объёма per-language, competitor gap, STAG-группы, RSA с валидацией длины 30/90.

---

## Рабочий стиль агента

- Язык общения — русский. Идентификаторы кода — английский.
- Действовать как senior-разработчик в связке с архитектором: лучшие практики, явный разбор trade-off.
- Сложное решение — сперва аргументы за обе стороны, потом вывод. Не прыгать к заключению.
- Перед крупным шагом — краткая сводка текущего состояния.
- Доводить фичу до полной готовности (тесты зелёные + self-review по `code-review` skill), не бросать на половине.
- Ошибся — честно признать и переориентироваться, без защитной воды.

---

## Зафиксированные решения

- **`project_id` — ПРИНЯТО.** `NOT NULL DEFAULT 1` (FK → `project`) на всех tenant-scoped таблицах, индексы ведут с `project_id`, `UNIQUE(project_id, import_hash)`, seed `project(id=1,'Site.pro')` — закладывается в Фазе 1. Tenant-изоляция/UI отложены (§13 spec).
- **Forbidden-список и `language→url`** — сидятся заглушками (редактируются в админке); реальные значения от Борцов Гроуп подменяют seed позже. Не блокер.
