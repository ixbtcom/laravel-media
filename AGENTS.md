# laravel-media — AGENTS.md

Этот файл читают и Claude Code, и Codex (и другие AI-агенты).  
Синхронизируется из `~/code/claude/templates/code/AGENTS.md` через `~/code/claude/scripts/sync.sh`.  
Проектные особенности — внизу в секции **Project specifics** (за маркером `<!-- project-specific -->`), там можно править вручную, sync эту секцию не трогает.

## Стек

<!-- project-specific:stack -->
TODO: язык / фреймворк / package manager
<!-- /project-specific:stack -->

## Команды разработки

<!-- project-specific:commands -->
TODO: install / dev / build / test / lint
<!-- /project-specific:commands -->

## DeepSeek delegation

Используются bash-утилиты на PATH: `ask-deepseek`, `deepseek-write`, `extract-chat`.  
Работают у любого агента (Claude Code, Codex, Aider).  
Подробнее: `~/code/claude/snippets/deepseek-routing.md`

## Git

- Чтение: `status`, `diff`, `log`, `show`, `branch` — без ограничений.
- Запись — только с явного разрешения: `add`, `commit`, `push`, `pull`, `merge`, `rebase`, `reset`, `stash`, `checkout`.
- Никогда `git checkout --` на незакоммиченных изменениях — необратимо.
- Никогда `git add -f` — если файл в .gitignore, сообщи.
- На новых ветках — всегда `git push -u`.

## Тесты

TDD: красный → зелёный → рефакторинг.  
Один минимальный тест → проверка что красный по делу → код → зелёный → рефакторинг.  
После unit-тестов — реальный запуск (CLI/API/UI). Coverage ≥ 80%.

## Project specifics

<!-- project-specific:body -->
TODO: архитектура, точки входа, gotchas, env-переменные
<!-- /project-specific:body -->
