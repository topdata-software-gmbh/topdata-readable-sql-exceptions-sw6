---
filename: "_ai/backlog/active/260330_1000__IMPLEMENTATION_PLAN__readable_sql_exceptions.md"
title: "Transform Unreadable MySQL Binary Constraint Violations into Human-Readable UUIDs"
createdAt: 2026-03-30 10:00
updatedAt: 2026-03-30 10:00
status: draft
priority: high
tags: [shopware6, sql, api, exception-handling, dbal, uuid]
estimatedComplexity: moderate
documentRevision: 1
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

When integrating ERP, PIM, or external systems via the Shopware 6 Admin API or Sync API, operations on mapping tables (e.g., `product_cross_selling_assigned_products`, `category_product`, `product_tag`) frequently fail with MySQL error `1062`:

```text
An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '\xF03\k\xFE\xE9LM\xB7\xD5h\xDAC\x05\x9E\x99-\xB9\x17x\x87\x14...' for key 'uniq.cross_selling_id__product_id__product_version_id'
```

Because Shopware 6 stores UUIDs as `BINARY(16)` fields, MySQL formats the conflicting key in its error string as a mixed escape sequence of raw bytes and ASCII characters. Shopware's default API exception handlers package this internal DBAL exception into a generic 500 Internal Server Error (or uninformative JSON:API error). 

This presents several critical issues:
1. **Unidentifiable Conflicts**: The client cannot determine which entity IDs caused the conflict without manually decoding raw byte dumps.
2. **Improper HTTP Semantics**: Duplicate key collisions are client-correctable concurrency or data integrity issues and should return `409 Conflict` rather than `500 Internal Server Error`.
3. **Automated Sync Stalls**: Integration pipelines cannot self-heal or dynamically resolve conflict IDs (e.g., switching from INSERT to UPDATE or dropping duplicates).

---

## 2. Executive Summary

This plan transforms the `topdata/readable-sql-exceptions-sw6` plugin from a skeleton into an automated exception translation engine.

The solution provides:
1. **Parser & Struct Layer (`DuplicateKeyParser`)**: Extracts the conflicting binary byte sequences and constraint index name from `UniqueConstraintViolationException` messages, un-escapes them, converts them into both 32-character hex UUIDs and standard hyphenated RFC 4122 UUIDs, and identifies potential column mappings.
2. **API Exception Interceptor (`UniqueConstraintExceptionSubscriber`)**: Hooks into Symfony's `KernelEvents::EXCEPTION` at high priority. When an API or Sync request fails due to duplicate key violations, it intercepts the exception and issues a clean `409 Conflict` response with structured conflict metadata.
3. **CLI Decoder Command (`topdata:readable-sql-exceptions:decode`)**: Provides developers and support teams with a CLI tool to paste raw MySQL error strings from server logs and immediately extract the decoded UUIDs.
4. **Housekeeping & Cleanup**: Strips out unnecessary default boilerplate files (dummy storefront controllers, assets, and tasks) that do not belong in this backend utility plugin.

---

## 3. Project Environment Details

- **Project Name:** Topdata Readable Sql Exceptions SW6
- **Plugin Class:** `Topdata\TopdataReadableSqlExceptionsSW6\TopdataReadableSqlExceptionsSW6`
- **Backend Root:** `src/`
- **Shopware Version:** `~6.7.0`
- **PHP Version:** `8.2 / 8.3 / 8.4` (Symfony 7.4, DBAL 4.4.x)
- **Plugin Type:** Backend utility / API enhancement

---

## 4. Architectural Design & SOLID Principles

- **Single Responsibility (SRP):**
  - `DuplicateKeyParser`: Pure string analysis and binary UUID extraction.
  - `UniqueConstraintViolation`: Value object/DTO containing decoded conflict information.
  - `UniqueConstraintExceptionSubscriber`: HTTP lifecycle listener responsible only for request inspection and response transformation.
  - `DecodeDuplicateKeyCommand`: Console wrapper for manual string inspection.
- **Open/Closed (OCP):**
  - The parsing contract `DuplicateKeyParserInterface` allows for database-specific parsing strategies if MariaDB or MySQL alter their constraint violation string formats.
- **Dependency Inversion (DIP):**
  - The subscriber depends on `DuplicateKeyParserInterface`, allowing unit testing without database or HTTP kernel mocks.

---

## 5. Implementation Phases

```
┌─────────────────────────────────────────────────────────────┐
│ Phase 1: Core Service Layer (DTO & Parser)                 │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│ Phase 2: HTTP Kernel Exception Subscriber (API Catch)       │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│ Phase 3: CLI Troubleshooting Command                        │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│ Phase 4: Purge Unneeded Boilerplate                         │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│ Phase 5: Documentation & Housekeeping                       │
└──────────────────────────────┬──────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────┐
│ Phase 6: Verification & Implementation Report               │
└─────────────────────────────────────────────────────────────┘
```

---

### Phase 1: Core Service Layer (DTO & Parser)

#### [NEW FILE] `src/Struct/DuplicateKeyViolation.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Struct;

use Shopware\Core\Framework\Struct\Struct;

class DuplicateKeyViolation extends Struct
{
    /**
     * @param string[] $hexUuids 32-character lowercase hex strings
     * @param string[] $formattedUuids 36-character RFC-4122 formatted UUIDs
     */
    public function __construct(
        public readonly string $constraintKey,
        public readonly array $hexUuids,
        public readonly array $formattedUuids,
        public readonly string $rawEntry,
        public readonly string $rawMessage
    ) {
    }

    public function toArray(): array
    {
        return [
            'constraintKey' => $this->constraintKey,
            'hexUuids' => $this->hexUuids,
            'formattedUuids' => $this->formattedUuids,
            'rawEntry' => $this->rawEntry,
        ];
    }
}
```

#### [NEW FILE] `src/Service/DuplicateKeyParserInterface.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Service;

use Topdata\TopdataReadableSqlExceptionsSW6\Struct\DuplicateKeyViolation;

interface DuplicateKeyParserInterface
{
    /**
     * Parses an SQL exception message for MySQL/MariaDB 1062 duplicate key patterns
     * and extracts decoded UUIDs.
     */
    public function parseMessage(string $errorMessage): ?DuplicateKeyViolation;
}
```

#### [NEW FILE] `src/Service/DuplicateKeyParser.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Service;

use Shopware\Core\Framework\Util\Uuid;
use Topdata\TopdataReadableSqlExceptionsSW6\Struct\DuplicateKeyViolation;

class DuplicateKeyParser implements DuplicateKeyParserInterface
{
    /**
     * Matches standard MySQL/MariaDB 1062 duplicate entry messages:
     * e.g. "Duplicate entry '...' for key '...'"
     */
    private const PATTERN = "/Duplicate entry '(?P<entry>.*)' for key '(?P<key>[^']+)'/s";

    public function parseMessage(string $errorMessage): ?DuplicateKeyViolation
    {
        if (!preg_match(self::PATTERN, $errorMessage, $matches)) {
            return null;
        }

        $rawEntry = $matches['entry'];
        $constraintKey = $matches['key'];

        // MySQL escapes binary data using C-style escapes (e.g., \xF0, \0, \', \\).
        // stripcslashes reconstructs the raw 16-byte chunks.
        $rawBytes = stripcslashes($rawEntry);
        $fullHex = bin2hex($rawBytes);

        if ($fullHex === '') {
            return null;
        }

        // Each Shopware UUID is 16 bytes = 32 hexadecimal characters
        $hexChunks = str_split($fullHex, 32);
        $hexUuids = [];
        $formattedUuids = [];

        foreach ($hexChunks as $chunk) {
            // Only convert exact 32-character hex representations
            if (strlen($chunk) === 32) {
                $hexUuids[] = strtolower($chunk);
                $formattedUuids[] = Uuid::fromHexToBytes($chunk) !== '' 
                    ? $this->formatRfc4122($chunk) 
                    : $chunk;
            }
        }

        return new DuplicateKeyViolation(
            constraintKey: $constraintKey,
            hexUuids: $hexUuids,
            formattedUuids: $formattedUuids,
            rawEntry: $rawEntry,
            rawMessage: $errorMessage
        );
    }

    private function formatRfc4122(string $hex): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
```

---

### Phase 2: HTTP Kernel Exception Subscriber

#### [NEW FILE] `src/Subscriber/UniqueConstraintExceptionSubscriber.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Subscriber;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Topdata\TopdataReadableSqlExceptionsSW6\Service\DuplicateKeyParserInterface;

#[AutoconfigureTag('kernel.event_subscriber')]
class UniqueConstraintExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DuplicateKeyParserInterface $duplicateKeyParser
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 15 ensures execution before Shopware's ApiBasicExceptionHandler and default ErrorListener
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 15],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Target API endpoints and JSON requests
        $path = $request->getPathInfo();
        $accept = (string) $request->headers->get('Accept', '');
        $isApi = str_starts_with($path, '/api') 
            || str_contains($accept, 'application/json')
            || str_contains($accept, 'application/vnd.api+json');

        if (!$isApi) {
            return;
        }

        $exception = $event->getThrowable();
        $constraintException = $this->findConstraintException($exception);

        if ($constraintException === null) {
            return;
        }

        $violation = $this->duplicateKeyParser->parseMessage($constraintException->getMessage());
        if ($violation === null) {
            return;
        }

        $errorPayload = [
            'errors' => [
                [
                    'status' => (string) Response::HTTP_CONFLICT,
                    'code' => 'FRAMEWORK__DUPLICATE_ENTRY_EXCEPTION',
                    'title' => 'Conflict / Duplicate Unique Constraint',
                    'detail' => sprintf(
                        'A unique constraint violation occurred on key "%s". Conflicting UUID(s): %s',
                        $violation->constraintKey,
                        implode(', ', $violation->hexUuids)
                    ),
                    'meta' => [
                        'constraint_key' => $violation->constraintKey,
                        'conflicting_hex_uuids' => $violation->hexUuids,
                        'conflicting_rfc_uuids' => $violation->formattedUuids,
                        'raw_constraint_entry' => $violation->rawEntry,
                    ],
                ],
            ],
        ];

        $event->setResponse(new JsonResponse($errorPayload, Response::HTTP_CONFLICT));
    }

    private function findConstraintException(\Throwable $throwable): ?UniqueConstraintViolationException
    {
        $current = $throwable;
        while ($current !== null) {
            if ($current instanceof UniqueConstraintViolationException) {
                return $current;
            }
            $current = $current->getPrevious();
        }

        return null;
    }
}
```

---

### Phase 3: CLI Troubleshooting Command

#### [NEW FILE] `src/Command/DecodeDuplicateKeyCommand.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataReadableSqlExceptionsSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Topdata\TopdataReadableSqlExceptionsSW6\Service\DuplicateKeyParserInterface;

#[AsCommand(
    name: 'topdata:readable-sql-exceptions:decode',
    description: 'Decodes a MySQL 1062 duplicate key error message into human-readable UUIDs'
)]
class DecodeDuplicateKeyCommand extends Command
{
    public function __construct(
        private readonly DuplicateKeyParserInterface $parser
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'errorMessage',
            InputArgument::REQUIRED,
            'The raw MySQL duplicate key error message or the escaped string'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawMessage = (string) $input->getArgument('errorMessage');

        // Allow passing either the full error message or just the escaped entry '\x...'
        if (!str_contains($rawMessage, 'Duplicate entry')) {
            $rawMessage = sprintf("Duplicate entry '%s' for key 'manual_input'", $rawMessage);
        }

        $violation = $this->parser->parseMessage($rawMessage);

        if ($violation === null || empty($violation->hexUuids)) {
            $io->error('Unable to parse or decode any binary UUIDs from the provided message.');
            return Command::FAILURE;
        }

        $io->title('Decoded MySQL Duplicate Key Information');
        $io->table(
            ['Property', 'Value'],
            [
                ['Constraint Key', $violation->constraintKey],
                ['Raw Entry', $violation->rawEntry],
            ]
        );

        $uuidRows = [];
        foreach ($violation->hexUuids as $idx => $hex) {
            $uuidRows[] = [
                'Index ' . ($idx + 1),
                $hex,
                $violation->formattedUuids[$idx] ?? 'N/A',
            ];
        }

        $io->section('Detected Shopware UUIDs');
        $io->table(['#', 'Hex UUID (Shopware DAL)', 'RFC 4122 (Standard)'], $uuidRows);

        return Command::SUCCESS;
    }
}
```

---

### Phase 4: Purge Unneeded Boilerplate & Update Services

The skeleton repository includes unnecessary storefront/scheduled-task demo files that should be deleted to prevent bloat.

#### [DELETE] Boilerplate files
- `src/Command/ExampleCommand.php`
- `src/Controller/AdminApiExampleController.php`
- `src/Controller/StorefrontExampleController.php`
- `src/Resources/views/storefront/example.html.twig`
- `src/Resources/app/storefront/src/js/main.js`
- `src/Resources/app/storefront/src/scss/base.scss`
- `src/ScheduledTask/ExampleTask.php`
- `src/ScheduledTask/ExampleTaskHandler.php`
- `src/Subscriber/ExampleSubscriber.php`
- `src/Resources/config/config.xml`

#### [MODIFY] `src/Resources/config/services.yaml`
```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    Topdata\TopdataReadableSqlExceptionsSW6\:
        resource: '../../{Command,Service,Subscriber}'
```

#### [MODIFY] `src/Resources/config/routes.xml`
```xml
<?xml version="1.0" encoding="UTF-8" ?>
<routes xmlns="http://symfony.com/schema/routing"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://symfony.com/schema/routing
        https://symfony.com/schema/routing/routing-1.0.xsd">
</routes>
```

#### [MODIFY] `composer.json`
```json
{
    "name": "topdata/readable-sql-exceptions-sw6",
    "description": "Translates unreadable binary MySQL 1062 duplicate key constraint exceptions into readable UUIDs in Shopware 6.7 API responses.",
    "version": "v1.0.0",
    "type": "shopware-platform-plugin",
    "license": "MIT",
    "authors": [
        {
            "name": "TopData Software GmbH",
            "homepage": "https://www.topdata.de",
            "role": "Manufacturer"
        }
    ],
    "require": {
        "shopware/core": "~6.7.0"
    },
    "extra": {
        "shopware-plugin-class": "Topdata\\TopdataReadableSqlExceptionsSW6\\TopdataReadableSqlExceptionsSW6",
        "plugin-icon": "src/Resources/config/plugin.png",
        "copyright": "(c) by TopData Software GmbH",
        "label": {
            "en-GB": "Topdata Readable SQL Exceptions SW6",
            "de-DE": "Topdata Lesbare SQL-Fehlermeldungen SW6"
        },
        "description": {
            "en-GB": "Transforms unreadable MySQL binary duplicate key errors into human-readable UUIDs.",
            "de-DE": "Wandelt unlesbare MySQL-Binary-Duplicate-Key-Fehler in lesbare UUIDs um."
        },
        "manufacturerLink": {
            "de-DE": "https://www.topdata.de",
            "en-GB": "https://www.topdata.de"
        }
    },
    "autoload": {
        "psr-4": {
            "Topdata\\TopdataReadableSqlExceptionsSW6\\": "src/"
        }
    }
}
```

---

### Phase 5: Documentation & Housekeeping

#### [MODIFY] `docs/index.md`
```markdown
# Topdata Readable SQL Exceptions SW6

Transforms cryptic MySQL binary duplicate key exceptions (`SQLSTATE[23000]: 1062 Duplicate entry '\x...'`) into structured HTTP 409 Conflict responses containing human-readable hex UUIDs.

## Features
- **Automatic API Interception**: Intercepts `Doctrine\DBAL\Exception\UniqueConstraintViolationException` during `/api` calls.
- **Hex & RFC 4122 Extraction**: Extracts every 16-byte UUID from composite unique keys.
- **CLI Utility**: Allows manual decoding of log messages via `bin/console topdata:readable-sql-exceptions:decode`.
- **Zero Configuration**: Works out of the box with zero database overhead.
```

#### [MODIFY] `README.md`
```markdown
# Topdata Readable SQL Exceptions SW6

Shopware 6.7 plugin that intercepts MySQL duplicate key constraint violations (`SQLSTATE[23000] - 1062`) and translates binary escaped UUIDs into clean, human-readable 32-character hexadecimal strings.

## The Problem
When syncing data (like product cross-sellings or category trees), MySQL outputs unreadable binary strings on constraint violations:
```text
Duplicate entry '\xF03\k\xFE\xE9LM\xB7\xD5h\xDAC\x05\x9E\x99-\xB9\x17x\x87\x14...' for key 'uniq.cross_selling_id__product_id__product_version_id'
```

## The Solution
This plugin intercepts the exception and returns a `409 Conflict` response with decoded UUIDs:

```json
{
  "errors": [
    {
      "status": "409",
      "code": "FRAMEWORK__DUPLICATE_ENTRY_EXCEPTION",
      "title": "Conflict / Duplicate Unique Constraint",
      "detail": "A unique constraint violation occurred on key \"uniq.cross_selling_id__product_id__product_version_id\". Conflicting UUID(s): f0336bfee94c4db7d568da43059e992d, b917788714654992be7e7ca4dc791b8a",
      "meta": {
        "constraint_key": "uniq.cross_selling_id__product_id__product_version_id",
        "conflicting_hex_uuids": [
          "f0336bfee94c4db7d568da43059e992d",
          "b917788714654992be7e7ca4dc791b8a"
        ],
        "conflicting_rfc_uuids": [
          "f0336bfe-e94c-4db7-d568-da43059e992d",
          "b9177887-1465-4992-be7e-7ca4dc791b8a"
        ]
      }
    }
  ]
}
```

## CLI Usage
You can decode raw log strings directly via console:
```bash
bin/console topdata:readable-sql-exceptions:decode "Duplicate entry '\xF03\k\xFE\xE9LM\xB7\xD5h\xDAC\x05\x9E\x99-\xB9\x17x\x87\x14' for key 'uniq.test'"
```

## Requirements
- Shopware `~6.7.0`
- PHP `8.2+`
```

#### [MODIFY] `CHANGELOG.md`
```markdown
# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-03-30

### Added
- Core `DuplicateKeyParser` service to decode MySQL binary C-escaped byte streams into UUIDs.
- `UniqueConstraintExceptionSubscriber` returning HTTP 409 Conflict with structured UUID metadata for API requests.
- Console command `topdata:readable-sql-exceptions:decode` for ad-hoc manual decoding from log files.

### Removed
- Removed skeleton demo controllers, scheduled tasks, and storefront assets.
```

---

### Phase 6: Verification & Implementation Report Generation

1. **Unit / Integration Testing**:
   - Verify that `DuplicateKeyParser::parseMessage()` handles standard ASCII printable characters and non-printable hex byte sequences correctly.
   - Verify that non-matching errors (e.g. `Syntax error` or foreign key constraints) pass through untouched without overhead.
   - Test CLI command with mock inputs:
     ```bash
     bin/console topdata:readable-sql-exceptions:decode "Duplicate entry '\x01\x23\x45\x67\x89\xAB\xCD\xEF\x01\x23\x45\x67\x89\xAB\xCD\xEF' for key 'test_key'"
     ```
2. **Generate Implementation Report**:
   - Write the completion report to `_ai/backlog/reports/260330_1000__IMPLEMENTATION_REPORT__readable_sql_exceptions.md` according to the required schema.

---

## 6. Implementation Report Template (For Final Phase)

```yaml
---
filename: "_ai/backlog/reports/260330_1000__IMPLEMENTATION_REPORT__readable_sql_exceptions.md"
title: "Report: Transform Unreadable MySQL Binary Constraint Violations into Human-Readable UUIDs"
createdAt: 2026-03-30 10:00
updatedAt: 2026-03-30 10:00
planFile: "_ai/backlog/active/260330_1000__IMPLEMENTATION_PLAN__readable_sql_exceptions.md"
project: "topdata-readable-sql-exceptions-sw6"
status: completed
filesCreated: 4
filesModified: 5
filesDeleted: 10
tags: [shopware6, sql, api, exception-handling, dbal, uuid]
documentType: IMPLEMENTATION_REPORT
---

