# Обзор архитектуры


> Каноничные детали рантайма: [ARCHITECTURE.md](../../../ARCHITECTURE.md)
> Актуальный план миграции: [MIGRATION.md](../../../MIGRATION.md)

## Назначение

Эта страница — русскоязычное архитектурное зеркало для контрибьюторов.
Она фиксирует то же практическое состояние, что и основная архитектурная документация: смешанный runtime (legacy + новый код), незавершённая миграция, явные границы модулей и контекстов.

## Текущее состояние в одном блоке

- Тип проекта: структурированный PHP-монолит
- Модель рантайма: смешанная (`ServiceContainer` + constructor injection + legacy globals)
- Основной web flow: `public/index.php` для панельных страниц + compatibility-пути для API/streaming
- Streaming: активный hot path всё ещё зависит от `www/stream/init.php`
- Модули: CLI-интеграция активна, web boot интеграция неполная
- Модель сборки: MAIN (полная) и LB (streaming-ориентированное подмножество)

## Роли директорий

- `src/core/`: инфраструктурные примитивы (DB, HTTP, Events, Config, Logging, Container)
- `src/domain/`: бизнес-контексты (Stream, Vod, Line, User, Server, Auth и др.)
- `src/streaming/`: слой delivery/auth/protection для stream endpoint'ов
- `src/public/`: front controller, routes, controllers, templates, assets
- `src/cli/`: слой выполнения команд и cron
- `src/modules/`: опциональный extension-слой
- `src/infrastructure/legacy/`: оставшийся compatibility-код
- `src/www/`: legacy-runtime поверхность, всё ещё задействованная в продовом трафике

## Известные разрывы (синхронизированы с EN)

1. Полный constructor injection пока цель, а не универсально соблюдённое правило.
2. `www/` пока нельзя безопасно удалять: на него завязаны nginx/runtime/service-процессы.
3. `ModuleLoader::bootAll()` не встроен в `public/index.php`, поэтому web lifecycle модулей неполный.
4. Часть public-контроллеров всё ещё делегирует в legacy/procedural handlers.

## Правила для контрибьюторов

1. Используйте [ARCHITECTURE.md](../../../ARCHITECTURE.md) и [MIGRATION.md](../../../MIGRATION.md) как источник истины по рантайму и очередности миграции.
2. Не документируйте «идеальное» состояние до фактического выполнения изменений в коде и инфраструктуре.
3. Держите EN и RU архитектурные страницы синхронными под одним Sync-ID.
4. Никогда не публикуйте секреты, API-ключи и внутренние credentials в клиентской части документации и примеров.

## Чеклист синхронизации

- Обновляйте [docs/ru/development/architecture.md](architecture.md) и [docs/en/development/architecture.md](../../en/development/architecture.md) в одном коммите.
- Сохраняйте одинаковые `Sync-ID` и дату актуализации.
- При значимых изменениях рантайма обновляйте и эту страницу, и корневой [ARCHITECTURE.md](../../../ARCHITECTURE.md).
