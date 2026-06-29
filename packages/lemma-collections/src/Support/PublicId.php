<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Support;

/**
 * Generates collision-resistant, URL-safe public identifiers (nanoid-style).
 *
 * Output format:  [{prefix}_]{16 random chars from [A-Za-z0-9]}
 * Examples: "prod_aBcD1234eFgH5678" (with prefix), "aBcD1234eFgH5678" (without).
 *
 * 16 chars from a 62-char alphabet → ~95 bits of entropy — sufficient for row-level IDs
 * without a coordination overhead. The `_` separator makes the prefix visually distinct
 * and the whole string URL-safe without encoding.
 *
 * Max output length: 16 (no prefix) or prefix_length + 1 + 16 (with prefix).
 * For a two-char prefix like "sc" the total is 19 chars — within the 24-char DB columns
 * used by lemma-collections.
 */
final class PublicId
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const SIZE     = 16;

    public static function generate(string $prefix = ''): string
    {
        $alphabet = self::ALPHABET;
        $alphabetLen = strlen($alphabet);
        $id = '';
        $bytes = random_bytes(self::SIZE);

        for ($i = 0; $i < self::SIZE; $i++) {
            $id .= $alphabet[ord($bytes[$i]) % $alphabetLen];
        }

        return $prefix === '' ? $id : $prefix . '_' . $id;
    }
}
