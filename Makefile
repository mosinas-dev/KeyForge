# KeyForge — local dev shortcuts. Run `make help` for the list.
.DEFAULT_GOAL := help
COMPOSE := docker compose

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Zero-touch: build, migrate+seed, serve (admin: http://localhost:8080/admin)
	$(COMPOSE) up -d

down: ## Stop the stack (keeps data)
	$(COMPOSE) down

fresh: ## Wipe everything (DB + vendor volume) and start clean
	$(COMPOSE) down -v
	$(COMPOSE) up -d --build

rebuild: ## Rebuild image after composer.json change (drops stale vendor volume)
	$(COMPOSE) down
	docker volume rm keyforge_keyforge_vendor || true
	$(COMPOSE) up -d --build

logs: ## Tail app logs
	$(COMPOSE) logs -f keyforge-app

shell: ## Open a shell in the app container
	$(COMPOSE) exec keyforge-app sh

migrate: ## Apply migrations manually (for when RUN_MIGRATIONS is off)
	$(COMPOSE) exec keyforge-app php yii migrate

test: ## Run the FULL suite (unit + integration on Postgres)
	$(COMPOSE) --profile test run --rm --build keyforge-test

pull: ## Pull the published image from GHCR (usage: make pull REPO=owner/repo)
	docker pull ghcr.io/$(REPO):latest
