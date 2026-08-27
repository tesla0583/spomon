<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

final class NameNormalizerTest extends TestCase
{
    public function test_different_case_normalizes_to_same_result(): void
    {
        self::assertSame(
            NameNormalizer::normalize('Файзуллоева Гулнора'),
            NameNormalizer::normalize('файзуллоева гулнора'),
        );
    }

    public function test_extra_and_repeated_whitespace_is_collapsed(): void
    {
        self::assertSame(
            NameNormalizer::normalize('Файзуллоева Гулнора'),
            NameNormalizer::normalize('  Файзуллоева   Гулнора  '),
        );
    }

    public function test_case_and_whitespace_differences_combined_normalize_to_same_result(): void
    {
        self::assertSame(
            'файзуллоева гулнора',
            NameNormalizer::normalize('  ФАЙЗУЛЛОЕВА    Гулнора '),
        );
    }
}
