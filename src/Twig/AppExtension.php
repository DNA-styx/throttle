<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('reldate', [$this, 'relativeDuration']),
            new TwigFilter('diffdate', [$this, 'diffDate']),
            new TwigFilter('identicon', [$this, 'identicon']),
            new TwigFilter('crashid', [$this, 'formatCrashId']),
            new TwigFilter('format_metadata_key', [$this, 'formatMetadataKey']),
            new TwigFilter('address', [$this, 'formatAddress']),
            new TwigFilter('wrap', [$this, 'wrapTag'], [
                'pre_escape' => 'html',
                'is_safe' => ['html'],
            ]),
        ];
    }

    public function relativeDuration(int $seconds): string
    {
        $result = '';

        if ($seconds >= 86400) {
            $days = intdiv($seconds, 86400);
            $seconds %= 86400;
            $result .= $days.' day'.($days === 1 ? '' : 's');
            if ($seconds > 0) {
                $result .= ', ';
            }
        }

        if ($seconds >= 3600) {
            $hours = intdiv($seconds, 3600);
            $seconds %= 3600;
            $result .= $hours.' hour'.($hours === 1 ? '' : 's');
            if ($seconds > 0) {
                $result .= ', ';
            }
        }

        if ($seconds >= 60) {
            $minutes = intdiv($seconds, 60);
            $seconds %= 60;
            $result .= $minutes.' minute'.($minutes === 1 ? '' : 's');
            if ($seconds > 0) {
                $result .= ', ';
            }
        }

        return $result.$seconds.' second'.($seconds === 1 ? '' : 's');
    }

    public function diffDate(int $timestamp): string
    {
        $diff = time() - $timestamp;
        $dayDiff = (int) floor($diff / 86400);

        if ($dayDiff === 0) {
            if ($diff < 60) {
                return 'just now';
            }

            if ($diff < 120) {
                return '1 minute ago';
            }

            if ($diff < 3600) {
                return floor($diff / 60).' minutes ago';
            }

            if ($diff < 7200) {
                return '1 hour ago';
            }

            if ($diff < 86400) {
                return floor($diff / 3600).' hours ago';
            }
        }

        if ($dayDiff === 1) {
            return '1 day ago';
        }

        if ($dayDiff < 7) {
            return $dayDiff.' days ago';
        }

        if ($dayDiff < 31) {
            return ceil($dayDiff / 7).' weeks ago';
        }

        if ($dayDiff < 60) {
            return '1 month ago';
        }

        return date('F Y', $timestamp);
    }

    public function identicon(string $string, int $size = 20): string
    {
        return sprintf(
            'https://secure.gravatar.com/avatar/%s?s=%d&r=any&default=identicon&forcedefault=1',
            md5($string),
            $size * 2
        );
    }

    public function formatCrashId(string $string): string
    {
        return implode('-', str_split(strtoupper($string), 4));
    }

    public function formatMetadataKey(string $string): string
    {
        $name = implode(' ', array_map(static function (string $part): string {
            return match (strtolower($part)) {
                'url', 'lsb', 'pid', 'guid', 'id', 'mvp' => strtoupper($part),
                default => ucfirst($part),
            };
        }, preg_split('/(?:(?<=[a-z])(?=[A-Z])|_|-)/', $string) ?: []));

        return match ($name) {
            'Prod' => 'Host Product',
            'Ver' => 'Host Version',
            'Rept' => 'Reporter',
            'Ptime' => 'Process Time',
            'Source Mod Path' => 'SourceMod Path',
            'Source Mod Version' => 'SourceMod Version',
            default => $name,
        };
    }

    public function formatAddress(string $string): string
    {
        return sprintf('0x%08s', $string);
    }

    public function wrapTag(string $child, string $tag): string
    {
        return sprintf('<%s>%s</%s>', $tag, $child, $tag);
    }
}
