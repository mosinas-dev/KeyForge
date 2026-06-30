# KeyForge

Платформа автоматизации работы маркетолога **Site.pro**: импорт ключевых слов →
чистка → группировка по языку/интенту → генерация Google Ads RSA → экспорт в
формат **Google Ads Editor**.

Стек: PHP 8.5 · Yii2 (advanced: `console` + `backend` + `common`) · PostgreSQL 18
(`pg_trgm`) · league/csv · Docker.

> Контекст, правила разработки и архитектура — в [`CLAUDE.md`](CLAUDE.md).
> Полный спец/алгоритм — [`docs/AGENTIC_SPEC.md`](docs/AGENTIC_SPEC.md);
> план по фазам — [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md);
> формат выгрузки — [`docs/gads_export_format.md`](docs/gads_export_format.md).

---

## Быстрый старт

```bash
docker compose up -d        # build → миграции+seed → админка на :8080  (или: make up)
```

Админка: **http://localhost:8080** → логин **`admin`** / **`admin-password`**
(дефолтный сид; смените пароль для shared/prod). Открывается на дашборде KeyForge:
upload → keywords → preview → export.

---

## Конвейер (CLI)

```bash
docker compose exec keyforge-app php yii keyforge/import sample_data/ahrefs_organic_keywords.csv
docker compose exec keyforge-app php yii keyforge/prepare-gads
docker compose exec keyforge-app php yii keyforge/export   # --outputDir=@runtime/export
```

- `import` распознаёт источник по **точному имени файла**; из коробки заведены
  четыре: `ahrefs_organic_keywords.csv`, `ahrefs_paid_keywords.csv`,
  `google_ads_keywords.csv`, `search_console_queries.csv` (см. `sample_data/`).
  Произвольные файлы грузятся через админку (там source-тип выбирается явно).
- `import` прогоняет стадии §2.1–2.6: ingest → junk → brand → language → intent →
  dedup → volume. Идемпотентен (повторный импорт того же файла → 0 новых).

---

## Тесты

```bash
make test     # полный сьют (unit + integration) в контейнере на реальном PostgreSQL
```

Интеграционные тесты идут на **реальном PostgreSQL** (не sqlite) — иначе `pg_trgm` и
advisory-локи не проверить (см. `CLAUDE.md` правило 7). `make test` сам мигрирует
выделенную тестовую БД `keyforge_test`; запуск `codecept` напрямую с хоста требует,
чтобы `keyforge_test` была предварительно мигрирована.

---

## CI / образ

GitHub Actions (`.github/workflows/ci.yml`): тесты (PHP 8.5 + PG18) → build →
push в GHCR. Образ multi-stage с тест-гейтом (детали — `CLAUDE.md` «Docker/CI» +
`Dockerfile`); runtime не собирается при красных unit-тестах.

---

## Лицензия

Проприетарная — см. [`LICENSE`](LICENSE). © 2026 Aleksander Mosin.
