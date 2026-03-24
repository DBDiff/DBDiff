<?php namespace DBDiff\Migration\Config;

/**
 * Utilities for safely encoding and decoding credentials in database
 * connection URLs.
 *
 * RFC 3986 requires that any character with special meaning in a URI be
 * percent-encoded when it appears inside the userinfo (user:password)
 * component.  For database passwords this matters most for:
 *
 *   Character | Encoded | Why it breaks a raw URL
 *   ----------+---------+----------------------------------------------
 *   @         | %40     | authority delimiter: splits user from host
 *   #         | %23     | fragment delimiter: truncates URL at this point
 *   ?         | %3F     | query-string start: moves rest to query
 *   /         | %2F     | path separator: splits path incorrectly
 *   %         | %25     | percent-encoding prefix (see warning below)
 *   :         | %3A     | scheme/port separator (extra colons in password)
 *   +         | %2B     | decoded as space in some parsers
 *   space     | %20     | whitespace
 *
 * ⚠ KNOWN LIMITATION — literal '%' followed by two hex digits:
 *   Under RFC 3986,  "%1F"  inside a URL always means byte chr(0x1F),
 *   never the two-character string "%1F".  There is no way to distinguish
 *   these two cases from the URL text alone.  If your password contains a
 *   literal '%' that is followed by two hex digits (e.g. "abc%1Fxyz"),
 *   you MUST either:
 *     • call encode() on the raw password and embed the result in the URL, or
 *     • manually replace '%' with '%25' before embedding it in the URL.
 *   For all other '%' positions (end of string, followed by non-hex chars,
 *   etc.) the normaliser handles them automatically.
 */
class DsnPasswordEncoder
{
    /**
     * Encode a raw credential value for safe embedding in a database URL.
     *
     * All characters except RFC 3986 unreserved characters (A–Z a–z 0–9 - _ . ~)
     * are percent-encoded.
     *
     * Example:  encode('p@ss#w%rd!')  →  'p%40ss%23w%25rd%21'
     *
     * @param  string $raw  Plain-text username or password.
     * @return string       Percent-encoded value safe for use in a URL.
     */
    public static function encode(string $raw): string
    {
        return rawurlencode($raw);
    }

    /**
     * Decode a percent-encoded credential back to its plain-text form.
     *
     * This is the inverse of encode(); it is equivalent to rawurldecode().
     *
     * Example:  decode('p%40ss%23w%25rd%21')  →  'p@ss#w%rd!'
     *
     * @param  string $encoded  Percent-encoded credential (as returned by parse_url).
     * @return string           Plain-text credential.
     */
    public static function decode(string $encoded): string
    {
        return rawurldecode($encoded);
    }

    /**
     * Normalise the userinfo portion of a DSN URL so it is fully
     * percent-encoded before parse_url() ever sees it.
     *
     * The normalisation applies a decode → encode round-trip to the user and
     * password components individually.  This is idempotent:
     *   • Already-encoded credentials (e.g. "p%40ss") are decoded then
     *     re-encoded, producing the same result.
     *   • Raw credentials (e.g. "p@ss", "p#ss", "p?ss") are encoded on the
     *     first call so parse_url receives a valid URL.
     *
     * The host, port, path, and query-string parts of the URL are not changed.
     *
     * Multiple '@' signs in the password are handled correctly because this
     * method locates the authority delimiter as the LAST '@' in the authority
     * component (before the path), matching RFC 3986 semantics.
     *
     * ⚠ See the class-level doc-block for the '%' + two-hex-digit limitation.
     *
     * @param  string $url  DSN URL with raw or pre-encoded credentials.
     * @return string       Equivalent URL with userinfo safely percent-encoded.
     */
    public static function normalizeUrl(string $url): string
    {
        $sep = strpos($url, '://');
        if ($sep === false) {
            return $url;
        }

        $scheme      = substr($url, 0, $sep);
        $afterScheme = substr($url, $sep + 3);

        // Locate the real authority delimiter.  Using strrpos means an '@'
        // inside the password (which should have been encoded but wasn't) does
        // not confuse us — the LAST '@' is always the user/host separator.
        $lastAt = strrpos($afterScheme, '@');
        if ($lastAt === false) {
            return $url; // No userinfo — nothing to normalise.
        }

        $rawUserinfo = substr($afterScheme, 0, $lastAt);
        $hostAndRest = substr($afterScheme, $lastAt + 1);

        // Split userinfo on the FIRST ':' only — extra colons belong to the
        // password, not to additional separators.
        $colonPos = strpos($rawUserinfo, ':');
        if ($colonPos === false) {
            $encodedUserinfo = rawurlencode(rawurldecode($rawUserinfo));
        } else {
            $rawUser = substr($rawUserinfo, 0, $colonPos);
            $rawPass = substr($rawUserinfo, $colonPos + 1);
            $encodedUserinfo = rawurlencode(rawurldecode($rawUser))
                . ':'
                . rawurlencode(rawurldecode($rawPass));
        }

        return "{$scheme}://{$encodedUserinfo}@{$hostAndRest}";
    }

    /**
     * Build a fully-encoded database connection URL from its raw components.
     *
     * All credential values are accepted as plain-text strings; encoding is
     * applied automatically.  This is the safest way to construct a DSN URL
     * when you have the individual components available, and it correctly
     * handles any character (including '%') in the password.
     *
     * @param  string               $scheme  Driver scheme: mysql, postgres, pgsql, postgresql, sqlite.
     * @param  string               $user    Plain-text username.
     * @param  string               $pass    Plain-text password (any characters accepted).
     * @param  string               $host    Hostname or IP address.
     * @param  int                  $port    TCP port.
     * @param  string               $dbName  Database name.
     * @param  array<string,string> $query   Optional query-string parameters (e.g. ['sslmode' => 'require']).
     * @return string               Fully-formed, safely-encoded DSN URL.
     */
    public static function buildUrl(
        string $scheme,
        string $user,
        string $pass,
        string $host,
        int    $port,
        string $dbName,
        array  $query = []
    ): string {
        $url = sprintf(
            '%s://%s:%s@%s:%d/%s',
            $scheme,
            rawurlencode($user),
            rawurlencode($pass),
            $host,
            $port,
            rawurlencode($dbName)
        );

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
