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
8. **PHP 8.5 идиомы.** **Constructor property promotion** во ВСЕХ классах — не объявлять поля отдельно и присваивать их в конструкторе (`public function __construct(private Connection $db) {}`, не `private Connection $db; … $this->db = $db;`). Все конкретные классы, не предназначенные для наследования, помечать **`final`** (по умолчанию — final; наследование — только осознанно). Так же использовать современные возможности языка (enum, readonly, `match`, named args, `??=`), где они уместны.
9. **DI, не `new` зависимостей.** Сервисы и порты получаются через DI-контейнер Yii2 (constructor injection / `Yii::createObject`), а не создаются `new` в вызывающем коде. Порт→адаптер биндится в `common/config/main.php` (`container.singletons`) — замена LLM/экспортёра = одна строка. Контроллер/команда = composition root: собирает стадии из инжектнутых зависимостей; рантайм-параметры (файл, источник) передаются явно. `new` допустим только для сборки рантайм-объектов (стадии, DTO), не для сервисов/портов.

10. **§14 — Идиомы и дизайн PHP 8.5 (обязательны для каждой реализации).** Уточняют/ужесточают «Принципы разработки»; при конфликте с прежним «прагматично, без DTO-абстракций» приоритет у §14 (value objects, Result-объекты, коллекции применяем).
    1. **Современные возможности PHP 8.5** где уместно: promotion, `readonly` свойства, `readonly class` для immutable, `enum` вместо строковых/числовых констант, `match` вместо больших `switch`, named args, nullsafe `?->`, first-class callables, `throw`-выражения, атрибуты вместо PHPDoc где поддерживается, `??=`, `str_contains/starts_with/ends_with`, `array_is_list`, `Random\Randomizer`. Не писать в старом стиле, если есть более чистая фича.
    2. **`declare(strict_types=1);`** в каждом файле; типизировать каждое свойство/параметр/возврат; избегать `mixed`.
    3. **Только constructor injection;** сервисы никогда не `new`. Рантайм-DTO/value-объекты создавать `new` можно.
    4. **Final по умолчанию** — пока наследование явно не требуется (доказанная точка расширения).
    5. **Immutable объекты** — DTO как `readonly class`; избегать мутабельного состояния где практично.
    6. **Value Objects вместо примитивов** (`Email`, `Money`, `Percentage`, `Keyword`, `SearchVolume`), где доменный тип улучшает читаемость/валидацию.
    7. **Без Primitive Obsession** — бизнес-концепты получают свой тип.
    8. **Никаких boolean-флагов** в API (`generate($data, true)`) — вместо них enum-режим (named arg) или отдельные методы.
    9. **Композиция вместо наследования** — Strategy/Policy/Decorator/Specification; без глубоких иерархий.
    10. **Одна ответственность на метод** — не смешивать SQL/валидацию/JSON/HTTP/логи/экспорт в одном методе.
    11. **Early returns** вместо вложенных `if`.
    12. **Описательные имена** (=правило 4); без сокращений, кроме общепринятых.
    13. **Без magic values** — `$status === OrderStatus::Completed`, не `=== 5`.
    14. **Enums** вместо строковых/числовых констант где уместно.
    15. **Result-объекты** (`ValidationResult`/`ImportResult`/`ExportResult` со статусом/ошибками/метаданными) вместо `bool` из операций.
    16. **Исключения — для исключительного;** ожидаемые бизнес-исходы → Result; неожиданные сбои → throw; не возвращать `false` как «ошибка».
    17. **Минимум мутабельного состояния** — новый immutable-объект вместо мутации.
    18. **Коллекции вместо массивов**, когда у списка есть бизнес-смысл (`KeywordCollection`, `ImportBatch`).
    19. **Интерфейсы** — бизнес зависит от абстракций (`KeywordRepositoryInterface`, `ExporterInterface`, `LlmInterface`); реализации через DI.
    20. **Без Service Locator** — не `Yii::$container->get(...)` в бизнес-логике; резолв только в composition root.
    21. **SOLID** — особенно SRP, DIP, ISP.
    22. **PSR** — PSR-1/4/12/3 (и PSR-18 если HTTP-клиент).
    23. **YAGNI first** — без CQRS/Event Sourcing/Outbox/Saga/Redis/async/воркеров/очередей/distributed locks, пока явно не попросили (=правило 6, §13).
    24. **Оптимизация только по факту** — без кэша/батчей/конкурентности без профиля/требований.
    25. **Self-documenting код** — выразительные имена > комментарии; комментарий объясняет «почему», не «что»; не удалять, устаревшие — обновлять (=правило 3).
    26. **Маленькие классы** — одна ответственность; дробить God Objects.
    27. **Явность > хитрость** — читаемость важнее краткости; мейнтейнер понимает без догадок.

11. **§15 — Yii2 как инфраструктура (обязательны).** Yii2 — инфраструктурный фреймворк, НЕ архитектура; домен/прикладные сервисы фреймворк-независимы.
    1. **Бизнес-логика не зависит от Yii** — никаких `Yii::$app`, ActiveRecord, Request/Response, Controller внутри domain/application-сервисов.
    2. **Только constructor injection** — не резолвить зависимости внутри бизнес-кода.
    3. **`Yii::$app` — только инфраструктура** (`->db/request/response/cache` лишь в инфраструктурных адаптерах, не в сервисах).
    4. **Сервисы — в DI-контейнере** (`common/config/main.php` → `container.singletons`); смена реализации = только смена конфига.
    5. **Тонкие контроллеры** — валидация ввода, создание request-DTO, вызов сервисов, возврат ответа; без бизнес-логики.
    6. **Console-команды — composition roots** — могут собирать рантайм-пайплайны и создавать рантайм-объекты (DTO/стадии/пайплайны), но НЕ создавать прикладные сервисы.
    7. **ActiveRecord — инфраструктура** — только load/persist/маппинг; без бизнес-логики; репозитории прячут AR от прикладного слоя.
    8. **Repository-интерфейсы** — бизнес зависит от `KeywordRepositoryInterface`, инфра даёт `PgKeywordRepository`; не зависеть от AR напрямую.
    9. **Транзакции — в application-слое** — оборачивают полную бизнес-операцию; не открывать в репозиториях (без вложенных).
    10. **Query Builder вместо raw SQL**, пока читаемо; raw SQL — только для PG-специфики/производительности/читаемости.
    11. **PG-возможности разрешены** — `pg_trgm`, GIN, advisory locks, JSONB, CTE, оконные функции, UPSERT, RETURNING; не ограничиваться ANSI искусственно.
    12. **Доступ к БД — в репозиториях;** бизнес-сервисы не выполняют SQL напрямую (`$repository->findDuplicates(...)`).
    13. **Валидация — в DTO/валидаторах**, не в контроллерах.
    14. **No Fat Models** — бизнес-правила в сервисах, не в AR/FormModel.
    15. **No God Services** — дробить (валидация/persistence/HTTP/AI/экспорт/логи раздельно).
    16. **Один репозиторий — один агрегат.**
    17. **Явные транзакции** — никаких неявных коммитов.
    18. **Исключения всплывают** — репозитории бросают; application решает retry/convert/log/Result; репозитории не глушат.
    19. **Инфраструктура за портами** — внешние системы за `LlmInterface`/`ExporterInterface`/`StorageInterface`/`ClockInterface`/`UuidGeneratorInterface`; не звать SDK из бизнес-логики.
    20. **Рантайм-параметры — рантайм** (путь к файлу, источник, ввод, назначение экспорта) — явно, НЕ через DI.
    21. **Не злоупотреблять AR-связями** — без eager-load огромных графов; возвращать ровно нужное.
    22. **Стриминг для больших данных** — генераторы/итераторы/курсоры; не грузить таблицы целиком.
    23. **Логирование через `Psr\Log\LoggerInterface`**, не Yii-логгер в бизнес-коде.
    24. **Время инжектируемо** — `ClockInterface`, не `new DateTimeImmutable()` в бизнес-логике.
    25. **UUID инжектируем** — `UuidGeneratorInterface`.
    26. **Testcontainers обязательны** — интеграционные на реальном PostgreSQL; SQLite запрещён (=правило 7).
    27. **Без скрытой магии фреймворка** — явная конфигурация > convention, где улучшает читаемость.

12. **Никаких запросов к БД без подтверждения человеком.** Любой агент-инициированный доступ к БД — ad-hoc `psql`, ручные `createCommand`-прогоны, запуск `yii keyforge/*` и прочих команд, читающих/пишущих БД, — выполняется ТОЛЬКО после явного апрува (показать запрос/команду → дождаться «да»). Мутации/деструктив на shared/staging/prod — всегда только с явным «да». **Исключение (как в правиле 2):** автоматический прогон тест-сьюта на одноразовой `keyforge_test` (механизм TDD, правила 1/7) и локальный zero-touch `docker compose up` на одноразовой `keyforge`.

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
│   │       ├── BrandClassifyStage.php
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
├── backend/                       # админка: RBAC, AdminLTE, upload, CRUD-ревью ключей, preview, export
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

**Docker/CI.** Локально — `docker compose up -d` (или `make up`): zero-touch с нуля — build → ждёт healthcheck БД → авто-migrate+seed → отдаёт админку на `:8080`. Полные тесты: `make test` (профиль `test`, unit+integration на реальном Postgres). Образ **multi-stage**: `base → vendor/vendor-dev → test(гейт) → runtime`; runtime не соберётся, если unit-тесты упали. CI (`.github/workflows/ci.yml`): тесты → build → push в **GHCR** (`ghcr.io/<owner>/<repo>:latest`). Redis (очередь) — за профилем (YAGNI). Раскладка: `console` (пайплайн) + `backend` (админка) + `common` (домен); отдельного `frontend`-приложения нет — upload-UI живёт в backend. Образ по умолчанию миграции не трогает (`RUN_MIGRATIONS`, opt-in).

---

## Логика чистки

SQL-first для множественных операций (dedup через `pg_trgm`, фильтры брендов/forbidden/объёма, идемпотентность через `import_hash UNIQUE`). PHP-слой — оркестрация, классификация язык/интент, LLM-генерация. Правила (бренды, forbidden, пороги, `language→url`) — данные в `kf_config_*`, НЕ хардкод.

Алгоритм по стадиям — §2 spec. Главные улучшения: junk → минус-слова (не удалять), детект языка по тексту (не доверять источнику), интент-фильтр, адаптивный порог объёма per-language, competitor gap, STAG-группы, RSA с валидацией длины 30/90.

---

## Рабочий стиль агента / взаимодействие

- Язык общения — **русский**. Идентификаторы кода — английский.
- Действовать как **senior / 10x-разработчик** в плотной связке с архитектором: лучшие практики, явный разбор trade-off.
- По умолчанию — **кратко и по делу**. Для сложных/архитектурных решений: два аргумента (за обе стороны) до вывода, не прыгать к заключению.
- Перед крупным шагом — **краткая сводка текущего состояния**. Разбивать задачу только на действительно необходимые шаги.
- **`context7`** — для документации библиотек/фреймворков/API (Yii2, league/csv и т.п.): тянуть актуальные доки, не полагаться на память.
- Результаты поиска — давать **TL;DR**, осторожно с «красными селёдками»/мусором в выдаче.
- Доводить фичу до полной готовности (тесты зелёные + self-review по `code-review` skill), не бросать на половине.
- Ошибся — честно признать и переориентироваться, без защитной воды. Не знаешь — «I don't know» (=правило 5).

---

## Зафиксированные решения

- **`project_id` — ПРИНЯТО.** `NOT NULL DEFAULT 1` (FK → `project`) на всех tenant-scoped таблицах, индексы ведут с `project_id`, `UNIQUE(project_id, import_hash)`, seed `project(id=1,'Site.pro')` — закладывается в Фазе 1. Tenant-изоляция/UI отложены (§13 spec).
- **Forbidden-список и `language→url`** — сидятся заглушками (редактируются в админке); реальные значения от Борцов Гроуп подменяют seed позже. Не блокер.
