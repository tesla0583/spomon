<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Набор полей `side_section` не позволяет определить сторону ни как физлицо
 * (doc_number + first_name + last_name), ни как юрлицо (tax_pay_number + name).
 *
 * См. CLAUDE.md, раздел "Структура XML СПО": тип стороны в form_101 отдельным тегом
 * не помечен и определяется исключительно по факту заполненных полей.
 */
final class UnrecognizedPartyDataException extends RuntimeException {}
