<?php
declare(strict_types=1);

namespace Panth\MagePos\Model;

class NameDecoder
{
    public static function decode(mixed $value): string
    {
        $text = is_scalar($value) ? (string)$value : '';
        if ($text === '' || !str_contains($text, '&')) {
            return $text;
        }

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
