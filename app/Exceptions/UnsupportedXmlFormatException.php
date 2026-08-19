<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * XML-файл не является поддерживаемым форматом `form_101` — либо это устаревший формат
 * с корневым тегом `Report` (см. CLAUDE.md, раздел "Структура XML СПО"), либо файл повреждён
 * / не является валидным XML вовсе.
 */
final class UnsupportedXmlFormatException extends RuntimeException {}
