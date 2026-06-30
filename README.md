# KeyForge

Платформа автоматизации работы маркетолога **Site.pro**: импорт ключевых слов из
нескольких источников → чистка → группировка по языку/интенту → генерация Google Ads
кампаний (RSA) → экспорт в формат **Google Ads Editor**.

Боль, которую закрываем: ручная чистка/группировка ключей и сборка кампаний. Цель —
быстрее, проще, **предсказуемо**.

**Стек:** PHP 8.5 · Yii2 (advanced: `console` + `backend` + `common`) · PostgreSQL 18
(`pg_trgm`) · league/csv · Docker · Codeception.

---

## Быстрый старт (zero-touch)

```bash
docker compose up -d        # build → ждёт healthcheck БД → авто-migrate+seed → :8080
# или: make up
```

- Админка: **http://localhost:8080** → логин **`admin`** / **`admin-password`**
  (дефолтный сид; смените пароль для shared/prod).
- Останавливается дефолтно на дашборде KeyForge: upload / keywords / preview / export.

`docker compose up -d` поднимает: `keyforge-postgres` (PG18), `keyforge-app` (php-fpm,
авто-миграции+seed), `keyforge-nginx` (отдаёт `backend/web` на :8080).

---

## Конвейер (CLI)

```bash
# внутри контейнера: docker compose exec keyforge-app php yii <cmd>
yii keyforge/import <file.csv>     # §2.1–2.6: ingest + чистка (junk/lang/intent/dedup/volume)
yii keyforge/prepare-gads          # §2.7–2.9: used/forbidden/competitor-gap, STAG-группы, RSA
yii keyforge/export [--outputDir=] # §2.10: campaigns.csv + negatives.csv (Google Ads Editor)
```

Источники (CSV) распознаются по имени файла (`sample_data/`): `ahrefs_organic`,
`ahrefs_paid`, `google_ads`, `search_console`. Импорт **идемпотентен** (повторный
импорт того же файла → 0 новых, по `import_hash`).

Формат выгрузки — см. [`docs/gads_export_format.md`](docs/gads_export_format.md).

---

## Тесты

```bash
make test          # полный сьют (unit + integration) в контейнере на реальном PostgreSQL
```

Локально (если порт PG проброшен на хост, `5432`):

```bash
KEYFORGE_DB_HOST=127.0.0.1 KEYFORGE_TEST_DB_NAME=keyforge_test YII_ENV=test \
  php vendor/bin/codecept run
```

Интеграционные тесты идут на **реальном PostgreSQL** (не sqlite) — иначе `pg_trgm`/
оконные функции не проверить. TDD: тесты первыми, edge-кейсы из `docs/AGENTIC_SPEC.md` §11.

---

## Архитектура (кратко)

- **Пайплайн** (`common/pipeline`): линейный `PipelineRunner` над `PipelineStage` —
  Ingest → Junk → Brand → Language → Intent → FuzzyDedup → Volume → GadsPrep →
  AdGeneration → Export. Оркестрация — `KeywordPipelineService`.
- **Порты/адаптеры** (§15): `KeywordSourceProvider` (Csv/Json), `AdCopyGenerator`
  (Template; реальный LLM — отложенная замена), `CampaignExporter` (GoogleAdsEditor).
- **Репозитории** (`common/repositories`): весь SQL за `*RepositoryInterface` /
  `Pg*Repository`; стадии не ходят в БД напрямую и не зависят от Yii.
- **Правила — данные** (`kf_config_*`): бренды, forbidden, пороги объёма,
  `language → url`. Не хардкод.
- **Схема**: префикс `kf_`, `project_id` на всех tenant-таблицах, `UNIQUE(project_id,
  import_hash)`, GIN `pg_trgm` на `normalized_keyword`.

Подробности — `docs/AGENTIC_SPEC.md`, план — `docs/IMPLEMENTATION_PLAN.md`, решения —
`docs/adr/`. Правила разработки и контекст для агентов — `CLAUDE.md`.

---

## CI / образ

GitHub Actions (`.github/workflows/ci.yml`): тесты (PHP 8.5 + PG18) → build →
push в **GHCR** (`ghcr.io/<owner>/<repo>:latest`). Образ multi-stage
(`base → vendor → test-гейт → runtime`); runtime не соберётся при красных unit-тестах.

---

## Лицензия

Проприетарная — см. [`LICENSE`](LICENSE). © 2026 Aleksander Mosin.
