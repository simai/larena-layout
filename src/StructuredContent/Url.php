<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/**
 * The part of the WHATWG URL parser the renderer leans on, without a base URL: whether a string is an absolute URL,
 * its scheme, and the serialisation (`href`) of an https:, mailto: or tel: URL. What the Framework's renderer asks of
 * `new URL(value)` is answered the same way for the URLs content holds; this is not a general URL library.
 */
final class Url
{
    private const SPECIAL = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21, 'file' => null];

    /** The scheme with its colon ("https:"), or null when `new URL(value)` would throw. */
    public static function protocol(string $value): ?string
    {
        return self::parse($value)['protocol'] ?? null;
    }

    /** The URL's href, or null when it does not parse. */
    public static function href(string $value): ?string
    {
        return self::parse($value)['href'] ?? null;
    }

    /** Whether the URL has a host (an https: URL needs one). */
    public static function hostname(string $value): ?string
    {
        return self::parse($value)['hostname'] ?? null;
    }

    /** @return array{protocol: string, href: string, hostname: string}|null */
    private static function parse(string $value): ?array
    {
        // Leading and trailing C0 controls and spaces go; tabs and line feeds anywhere go.
        $input = (string) preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/D', '', $value);
        $input = str_replace(["\t", "\n", "\r"], '', $input);
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/sD', $input, $match) !== 1) {
            return null;
        }
        $scheme = strtolower($match[1]);
        $rest = $match[2];
        if (! array_key_exists($scheme, self::SPECIAL)) {
            // A non-special URL: an opaque path, or //authority.
            return ['protocol' => $scheme.':', 'href' => $scheme.':'.self::encodeOpaque($rest), 'hostname' => ''];
        }
        if ($scheme === 'file') {
            return ['protocol' => 'file:', 'href' => 'file:'.$rest, 'hostname' => ''];
        }
        $rest = (string) preg_replace('#^[/\\\\]+#', '', $rest);
        $end = strcspn($rest, '/\\?#');
        $authority = substr($rest, 0, $end);
        $tail = substr($rest, $end);
        $userinfo = '';
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $userinfo = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
        }
        $port = '';
        if (str_starts_with($authority, '[')) {
            if (preg_match('/^(\[[0-9A-Fa-f:.]+\])(?::([0-9]*))?$/D', $authority, $hostPort) !== 1) {
                return null;
            }
            $authority = $hostPort[1];
            $port = $hostPort[2] ?? '';
        } else {
            $colon = strpos($authority, ':');
            if ($colon !== false) {
                $port = substr($authority, $colon + 1);
                $authority = substr($authority, 0, $colon);
                if (preg_match('/^[0-9]*$/D', $port) !== 1) {
                    return null;
                }
            }
            if ($authority === '' || preg_match('/[\x00-\x20#%\/:<>?@\[\\\\\]^|\x7f]/', $authority) === 1) {
                return null;
            }
        }
        if ($port !== '') {
            if ((int) $port > 65535) {
                return null;
            }
            $port = (int) $port === self::SPECIAL[$scheme] ? '' : (string) (int) $port;
        }
        $host = mb_strtolower($authority, 'UTF-8');
        if (preg_match('/[^\x00-\x7f]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = $ascii;
        }
        $fragment = '';
        $hash = strpos($tail, '#');
        if ($hash !== false) {
            $fragment = '#'.self::encode(substr($tail, $hash + 1), '`"<> ');
            $tail = substr($tail, 0, $hash);
        }
        $query = '';
        $question = strpos($tail, '?');
        if ($question !== false) {
            $query = '?'.self::encode(substr($tail, $question + 1), "\"#<>' ");
            $tail = substr($tail, 0, $question);
        }
        $path = str_replace('\\', '/', $tail);
        $path = $path === '' ? '/' : self::encode($path, '"#<>?`{} ');

        return [
            'protocol' => $scheme.':',
            'href' => $scheme.'://'.($userinfo !== '' ? $userinfo.'@' : '').$host.($port !== '' ? ':'.$port : '').$path.$query.$fragment,
            'hostname' => $host,
        ];
    }

    private static function encodeOpaque(string $value): string
    {
        return self::encode($value, '');
    }

    /** Percent-encodes C0 controls, bytes above ASCII and the listed characters. */
    private static function encode(string $value, string $also): string
    {
        $out = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; $index++) {
            $byte = $value[$index];
            $code = ord($byte);
            $out .= $code < 0x20 || $code > 0x7E || ($also !== '' && str_contains($also, $byte)) ? sprintf('%%%02X', $code) : $byte;
        }

        return $out;
    }
}
