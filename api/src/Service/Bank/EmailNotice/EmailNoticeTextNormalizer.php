<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

final class EmailNoticeTextNormalizer
{
    public function normalize(string $input): string
    {
        $text = quoted_printable_decode($this->extractBody($input));
        if (!mb_check_encoding($text, 'UTF-8')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($clean !== false) {
                $text = $clean;
            }
        }
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $text) ?? $text;
        if (str_contains($text, '<') && str_contains($text, '>')) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;
        return trim($text);
    }

    private function extractBody(string $input): string
    {
        foreach ([
            '/Content-Transfer-Encoding:\s*quoted-printable\b.*?Content-Type:\s*text\/html\b.*?\R\R(?<body>.*?)(?=\R-{2,}=_|\z)/is',
            '/Content-Type:\s*text\/html\b.*?Content-Transfer-Encoding:\s*quoted-printable\b.*?\R\R(?<body>.*?)(?=\R-{2,}=_|\z)/is',
            '/Content-Type:\s*text\/plain\b.*?\R\R(?<body>.*?)(?=\R-{2,}=_|\z)/is',
        ] as $pattern) {
            if (preg_match($pattern, $input, $m) === 1) {
                return (string) $m['body'];
            }
        }

        if (preg_match('/(?:<!DOCTYPE|<html\b)/i', $input, $m, PREG_OFFSET_CAPTURE) === 1) {
            return substr($input, (int) $m[0][1]);
        }

        return $input;
    }
}
