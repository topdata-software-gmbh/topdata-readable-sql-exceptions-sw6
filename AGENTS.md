# AGENTS.md — Topdata Readable Sql Exceptions SW6

Guidance for AI agents (and humans) working in this Shopware 6.7 plugin.

## Naming conventions

These prefixes MUST be used consistently. Do not abbreviate or rename them.

### Command name prefix

All console commands MUST use the prefix:

```
topdata:readable-sql-exceptions:
```

Examples:
- `topdata:readable-sql-exceptions:example`
- `topdata:readable-sql-exceptions:import`
- `topdata:readable-sql-exceptions:sync`

Do NOT use an abbreviated vendor prefix (e.g. `tdaf:`); always use the full vendor segment from the command prefix above.

### Database table prefix

All custom tables created by this plugin MUST use the prefix:

```
topdata_readable_sql_exceptions_
```

Examples:
- `topdata_readable_sql_exceptions_activity`
- `topdata_readable_sql_exceptions_log`

Entity definitions, migrations and DAL table names must all follow this prefix.

## Namespacing

- PHP namespace root: `Topdata\TopdataReadableSqlExceptionsSW6\`
- PSR-4 autoload maps `src/` to that namespace (see `composer.json`).

## Structure

- `src/Command/` — console commands (command name prefix above)
- `src/Migration/` — DB migrations (table prefix above)
- `src/ScheduledTask/` — scheduled tasks
- `src/Service/` — business logic
- `src/Subscriber/` — event subscribers
- `src/Controller/` — admin/api & storefront controllers
- `src/Resources/` — config, snippets, views, assets