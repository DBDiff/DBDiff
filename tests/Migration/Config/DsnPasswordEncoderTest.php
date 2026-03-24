<?php

use DBDiff\Migration\Config\DsnParser;
use DBDiff\Migration\Config\DsnPasswordEncoder;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for DsnPasswordEncoder and for DsnParser's handling of
 * passwords embedded in connection URLs.
 *
 * Three test families:
 *
 *  1. encode() / decode() unit tests — pure function behaviour.
 *  2. normalizeUrl() unit tests — URL rewriting in isolation.
 *  3. buildUrl() + DsnParser::parse() round-trip — end-to-end proof that any
 *     raw password survives the full encode → URL → parse → decode cycle.
 *  4. Raw-password-in-URL tests — proactive normalisation behaviour: the user
 *     pastes a raw connection string (with un-encoded special chars) and the
 *     parser must still recover the correct password.
 *  5. Documented edge cases — corner-cases with explicit documentation of
 *     expected (and surprising) behaviour, including the '%' + hex limitation.
 */
class DsnPasswordEncoderTest extends TestCase
{
    // =========================================================================
    // 1. encode() / decode() — pure function unit tests
    // =========================================================================

    public function testEncodeAlphanumericIsUnchanged(): void
    {
        $this->assertSame('password123', DsnPasswordEncoder::encode('password123'));
    }

    public function testEncodeAtSign(): void
    {
        $this->assertSame('p%40ss', DsnPasswordEncoder::encode('p@ss'));
    }

    public function testEncodeHash(): void
    {
        $this->assertSame('p%23ss', DsnPasswordEncoder::encode('p#ss'));
    }

    public function testEncodeQuestionMark(): void
    {
        $this->assertSame('p%3Fss', DsnPasswordEncoder::encode('p?ss'));
    }

    public function testEncodeForwardSlash(): void
    {
        $this->assertSame('p%2Fss', DsnPasswordEncoder::encode('p/ss'));
    }

    public function testEncodePercent(): void
    {
        $this->assertSame('p%25ss', DsnPasswordEncoder::encode('p%ss'));
    }

    public function testEncodePercentFollowedByHex(): void
    {
        // Encode treats the raw string literally — '%1F' becomes '%251F',
        // meaning a literal percent sign followed by '1F'.
        $this->assertSame('abc%251Fxyz', DsnPasswordEncoder::encode('abc%1Fxyz'));
    }

    public function testEncodeColon(): void
    {
        $this->assertSame('p%3Ass', DsnPasswordEncoder::encode('p:ss'));
    }

    public function testEncodePlus(): void
    {
        $this->assertSame('p%2Bss', DsnPasswordEncoder::encode('p+ss'));
    }

    public function testEncodeSpace(): void
    {
        $this->assertSame('my%20pass', DsnPasswordEncoder::encode('my pass'));
    }

    public function testEncodeEmpty(): void
    {
        $this->assertSame('', DsnPasswordEncoder::encode(''));
    }

    public function testEncodeUnicode(): void
    {
        $encoded = DsnPasswordEncoder::encode('pässwörd');
        $this->assertStringContainsString('%', $encoded);
        $this->assertNotSame('pässwörd', $encoded);
    }

    public function testDecodeRoundTrip(): void
    {
        $raw = 'p@ss#w0rd?/:%+!$&\'()*,;= []{}^`|~';
        $this->assertSame($raw, DsnPasswordEncoder::decode(DsnPasswordEncoder::encode($raw)));
    }

    public function testDecodeDecodesPercentEncoded(): void
    {
        $this->assertSame('p@ss#w0rd', DsnPasswordEncoder::decode('p%40ss%23w0rd'));
    }

    public function testDecodeAlphanumericIsUnchanged(): void
    {
        $this->assertSame('password123', DsnPasswordEncoder::decode('password123'));
    }

    public function testDecodeEmpty(): void
    {
        $this->assertSame('', DsnPasswordEncoder::decode(''));
    }

    public function testEncodeDecodeIsInverseForArbitraryStrings(): void
    {
        $samples = [
            '',
            'simple',
            'p@ss',
            'p#ss',
            'p?ss',
            'p/ss',
            'p%ss',
            'p ss',
            'p:ss',
            'p+ss',
            "p\nss",
            'special !$&\'()*+,;=',
            'multiple@@@@signs',
            'slash//pass//word',
            'abc%1Fxyz',   // % + hex — encode gives %25 prefix, round-trip works
            'pässwörd',    // multi-byte UTF-8
        ];

        foreach ($samples as $raw) {
            $this->assertSame(
                $raw,
                DsnPasswordEncoder::decode(DsnPasswordEncoder::encode($raw)),
                "encode→decode round-trip failed for: " . json_encode($raw)
            );
        }
    }

    // =========================================================================
    // 2. normalizeUrl() — URL rewriting
    // =========================================================================

    public function testNormalizeUrlNoUserinfoIsUnchanged(): void
    {
        $url = 'postgres://localhost:5432/db';
        $this->assertSame($url, DsnPasswordEncoder::normalizeUrl($url));
    }

    public function testNormalizeUrlNotAUrlIsUnchanged(): void
    {
        $this->assertSame('not-a-url', DsnPasswordEncoder::normalizeUrl('not-a-url'));
    }

    public function testNormalizeUrlSimplePasswordUnchanged(): void
    {
        $url = 'postgres://user:password@host:5432/db';
        $this->assertSame($url, DsnPasswordEncoder::normalizeUrl($url));
    }

    public function testNormalizeUrlEncodesAtInPassword(): void
    {
        $in  = 'postgres://user:p@ss@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('p%40ss', $out);
        $this->assertStringContainsString('@host:5432', $out);
    }

    public function testNormalizeUrlEncodesHashInPassword(): void
    {
        $in  = 'postgres://user:p#ss@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('p%23ss', $out);
    }

    public function testNormalizeUrlEncodesQuestionMarkInPassword(): void
    {
        $in  = 'postgres://user:p?ss@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('p%3Fss', $out);
    }

    public function testNormalizeUrlEncodesSlashInPassword(): void
    {
        $in  = 'postgres://user:p/ss@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('p%2Fss', $out);
    }

    public function testNormalizeUrlPreservesQueryString(): void
    {
        $in  = 'postgres://user:p#ss@host:5432/db?sslmode=require';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('sslmode=require', $out);
    }

    public function testNormalizeUrlIdempotentOnAlreadyEncoded(): void
    {
        // Running normalizeUrl twice on a correctly-encoded URL should be a no-op.
        $url  = 'postgres://user:p%40ss%23word@host:5432/db';
        $once = DsnPasswordEncoder::normalizeUrl($url);
        $twice = DsnPasswordEncoder::normalizeUrl($once);
        $this->assertSame($once, $twice);
    }

    public function testNormalizeUrlHandlesMultipleAtSignsInPassword(): void
    {
        // Password contains two @ — strrpos must pick the LAST @ as the separator.
        $in  = 'postgres://user:p@a@b@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('@host:5432', $out);
        $this->assertStringNotContainsString(':p@a@b@', $out); // raw form gone
    }

    public function testNormalizeUrlHandlesExtraColonsInPassword(): void
    {
        // Password contains ':' — only the FIRST colon in userinfo is the separator.
        $in  = 'postgres://user:p:a:b@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        // 'p:a:b' encodes colons giving 'p%3Aa%3Ab'
        $this->assertStringContainsString('p%3Aa%3Ab', $out);
    }

    public function testNormalizeUrlUsernameOnly(): void
    {
        $url = 'postgres://user@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($url);
        $this->assertSame($url, $out); // 'user' is safe, no change needed
    }

    public function testNormalizeUrlEncodesAtInUsername(): void
    {
        $in  = 'postgres://us@er:pass@host:5432/db';
        $out = DsnPasswordEncoder::normalizeUrl($in);
        $this->assertStringContainsString('us%40er:', $out);
    }

    // =========================================================================
    // 3. buildUrl() + parse() round-trip
    //    buildUrl() uses rawurlencode, so ANY raw password survives correctly.
    // =========================================================================

    /** @dataProvider anyPasswordProvider */
    public function testBuildUrlRoundTrip(string $rawPassword, string $description): void
    {
        $url    = DsnPasswordEncoder::buildUrl('postgres', 'testuser', $rawPassword, 'localhost', 5432, 'db');
        $result = DsnParser::parse($url)['password'];
        $this->assertSame(
            $rawPassword,
            $result,
            "buildUrl round-trip failed for password: {$description}"
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function anyPasswordProvider(): array
    {
        return [
            // --- safe passwords ---
            'empty'                  => ['', 'empty string'],
            'alphanumeric'           => ['password123', 'alphanumeric'],
            'uppercase'              => ['UPPERCASE', 'uppercase'],
            'camel'                  => ['CamelCase99', 'camel case'],
            'long_safe'              => [str_repeat('aB3', 50), '150 char alphanumeric'],

            // --- single URL-special characters ---
            'at_sign'                => ['p@ssword', '@ in password'],
            'hash'                   => ['p#ssword', '# in password'],
            'question_mark'          => ['p?ssword', '? in password'],
            'forward_slash'          => ['p/ssword', '/ in password'],
            'percent'                => ['p%ssword', '% not followed by hex'],
            'percent_at_end'         => ['password%', '% at end'],
            'percent_alone'          => ['%', 'bare percent'],
            'colon'                  => ['p:ssword', ': in password'],
            'plus'                   => ['p+ssword', '+ in password'],
            'space'                  => ['my password', 'space'],
            'equals'                 => ['p=ssword', '= in password'],
            'ampersand'              => ['p&ssword', '& in password'],
            'exclamation'            => ['p!ssword', '! in password'],
            'dollar'                 => ['p$ssword', '$ in password'],
            'tilde'                  => ['p~ssword', '~ (unreserved, safe)'],
            'hyphen'                 => ['p-ssword', '- (unreserved, safe)'],
            'dot'                    => ['p.ssword', '. (unreserved, safe)'],
            'underscore'             => ['p_ssword', '_ (unreserved, safe)'],

            // --- percent followed by hex digits (the tricky case) ---
            // These MUST go through buildUrl/encode, not embedded raw.
            'percent_hex_upper'      => ['abc%1Fxyz', '% followed by uppercase hex'],
            'percent_hex_lower'      => ['abc%1fxyz', '% followed by lowercase hex'],
            'percent_12'             => ['abc%12xyz', '% followed by 12 (the failing real-world case)'],
            'percent_ff'             => ['abc%FFxyz', '% followed by FF'],
            'percent_00'             => ['abc%00xyz', '% followed by 00'],
            'percent_then_percent'   => ['50%%off', 'double percent sign'],

            // --- multiple special chars ---
            'at_and_hash'            => ['p@ss#word', '@ and # in password'],
            'at_hash_question'       => ['p@#?/:%word', 'multiple URL-special chars'],
            'supabase_typical'       => ['x67V8RRf3QvhHcPE', 'typical Supabase generated password'],
            'supabase_excl'          => ['n9Kp!mQ2@rX#vT4$', 'mixed special Supabase-style password'],

            // --- multiple @ signs ---
            'two_at_signs'           => ['p@ss@word', 'two @ signs'],
            'three_at_signs'         => ['a@b@c@d', 'three @ signs'],

            // --- multiple slashes ---
            'two_slashes'            => ['p//word', 'two forward slashes'],

            // --- whitespace variants ---
            'leading_space'          => [' password', 'leading space'],
            'trailing_space'         => ['password ', 'trailing space'],
            'tab'                    => ["pass\tword", 'tab character'],
            'newline'                => ["pass\nword", 'newline'],

            // --- unicode ---
            'latin_extended'         => ['pässwörd', 'Latin extended (UTF-8)'],
            'cjk'                    => ['密码ABC', 'CJK characters'],
            'emoji'                  => ['pass🔑word', 'emoji'],

            // --- entire ASCII printable special chars ---
            'all_special'            => ['!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', 'all ASCII printable punctuation'],

            // --- extreme lengths ---
            'very_short'             => ['x', 'single character'],
            'very_long'              => [str_repeat('abcDEF123@#?%', 20), '260-char mixed password'],
        ];
    }

    // =========================================================================
    // 4. Raw-password-in-URL (proactive normalisation in DsnParser::parse)
    //    The user embeds the raw password directly — no manual encoding.
    //    normalizeUrl() must recover the correct credential.
    // =========================================================================

    /** @dataProvider rawPasswordUrlProvider */
    public function testRawPasswordInUrlParsedCorrectly(
        string $rawPassword,
        string $description
    ): void {
        $url    = "postgres://testuser:{$rawPassword}@localhost:5432/testdb";
        $result = DsnParser::parse($url)['password'];
        $this->assertSame(
            $rawPassword,
            $result,
            "Raw-password-in-URL failed for: {$description}"
        );
    }

    /**
     * @return array<string, array{string, string}>
     *
     * Only passwords where raw embedding is safe (no '%' + valid-hex sequences).
     * The '%' + hex limitation is tested separately with explicit documentation.
     */
    public static function rawPasswordUrlProvider(): array
    {
        return [
            // --- the four historically broken characters ---
            'raw_at'                 => ['p@ssword', '@ — was auto-handled by retry, still works'],
            'raw_hash'               => ['p#ssword', '# — fixed by normalizeUrl proactive pass'],
            'raw_question'           => ['p?ssword', '? — fixed by normalizeUrl proactive pass'],
            'raw_slash'              => ['p/ssword', '/ — fixed by normalizeUrl proactive pass'],

            // --- multiple broken chars ---
            'raw_at_hash'            => ['p@ss#word', 'both @ and #'],
            'raw_at_slash'           => ['p@ss/word', 'both @ and /'],
            'raw_hash_question'      => ['p#ss?word', '# and ?'],
            'raw_all_four'           => ['a@b#c?d/e', 'all four special chars'],

            // --- multiple @ signs ---
            'raw_two_at'             => ['user@domain.com', 'email-like password'],
            'raw_three_at'           => ['a@b@c', 'three @ signs'],

            // --- colon in password ---
            'raw_extra_colon'        => ['pass:word:123', 'extra colons in password'],

            // --- safe chars (sanity checks) ---
            'safe_alphanumeric'      => ['password123', 'alphanumeric'],
            'safe_mixed_case'        => ['MyP4ssW0rd', 'mixed case'],
            'safe_supabase'          => ['x67V8RRf3QvhHcPE', 'typical Supabase password'],
            'safe_hyphen_dot'        => ['my-p4ss.w0rd', 'hyphen and dot (unreserved chars)'],
            'safe_tilde_underscore'  => ['my~pass_word', 'tilde and underscore (unreserved chars)'],

            // --- percent NOT followed by two valid hex digits ---
            'raw_percent_nonhex'     => ['p%ssword', '% followed by non-hex (s)'],
            'raw_percent_at_end'     => ['password%', '% at end of string'],
            'raw_percent_one_char'   => ['p%1', '% followed by only one char'],
            'raw_percent_bang'       => ['p%!word', '% followed by !'],
            'raw_percent_space'      => ['p% word', '% followed by space'],

            // --- exclamation, dollar, etc. (safe in path but good to verify) ---
            'raw_exclamation'        => ['p!ssword', '! character'],
            'raw_dollar'             => ['p$ssword', '$ character'],
            'raw_equals'             => ['p=ssword', '= character'],
            'raw_plus'               => ['p+ssword', '+ character'],
            'raw_comma'              => ['p,ssword', ', character'],
            'raw_semicolon'          => ['p;ssword', '; character'],
            'raw_brackets'           => ['p[s]word', '[ ] brackets'],
            'raw_caret'              => ['p^ssword', '^ character'],
            'raw_backtick'           => ['p`ssword', '` backtick'],
            'raw_pipe'               => ['p|ssword', '| pipe'],
            'raw_backslash'          => ['p\\ssword', '\\ backslash'],
            'raw_space'              => ['my password', 'space in password'],
            'raw_ampersand'          => ['p&ssword', '& in password'],
        ];
    }

    // =========================================================================
    // 5. Host and query-string integrity
    //    Confirm normalizeUrl does not corrupt host, port, db, or query string.
    // =========================================================================

    public function testHostIsPreservedAfterNormalization(): void
    {
        $r = DsnParser::parse('postgres://user:p@ss#word@db.example.com:5432/mydb');
        $this->assertSame('db.example.com', $r['host']);
    }

    public function testPortIsPreservedAfterNormalization(): void
    {
        $r = DsnParser::parse('postgres://user:p@ss#word@db.example.com:5555/mydb');
        $this->assertSame(5555, $r['port']);
    }

    public function testDbNameIsPreservedAfterNormalization(): void
    {
        $r = DsnParser::parse('postgres://user:p@ss#word@db.example.com:5432/myspecialdb');
        $this->assertSame('myspecialdb', $r['name']);
    }

    public function testQueryStringIsPreservedAfterNormalization(): void
    {
        $r = DsnParser::parse('postgres://user:p@ss#word@db.example.com:5432/mydb?sslmode=require&pgbouncer=true');
        $this->assertSame('require', $r['sslmode']);
        $this->assertTrue($r['pgbouncer']);
    }

    public function testUsernameIsPreservedAfterNormalization(): void
    {
        $r = DsnParser::parse('postgres://testuser:p@ss#word@db.example.com:5432/mydb');
        $this->assertSame('testuser', $r['user']);
    }

    // =========================================================================
    // 6. Documented edge cases — '%' followed by valid hex digits
    //
    //    This is the KNOWN LIMITATION described in DsnPasswordEncoder's doc.
    //    Tests here document the expected behaviour rather than asserting "this
    //    should just work", so that future developers understand the constraint.
    // =========================================================================

    /**
     * When a URL contains '%12' in the password position, RFC 3986 defines
     * this as the byte chr(0x12), NOT the two-character string '%12'.
     *
     * This is the root cause of the user-reported failure:
     *   --server1-url="...postgres:abcdefghixyz%123567890@..."
     *
     * The fix for end-users: call DsnPasswordEncoder::encode() on the raw
     * password before embedding it in the URL, OR write '%25' instead of '%'
     * when constructing the URL by hand.
     */
    public function testPercentFollowedByHexIsDecodedAsUrlEncodedByte(): void
    {
        // '%12' in URL = byte chr(0x12) per RFC 3986 — this is expected behaviour.
        $url = 'postgres://user:abc%12xyz@localhost:5432/db';
        $r = DsnParser::parse($url);
        $this->assertSame(
            'abc' . chr(0x12) . 'xyz',
            $r['password'],
            'Per RFC 3986, %12 in a URL means the byte chr(0x12). '
            . 'Use %2512 in the URL to embed the literal string %12 in a password.'
        );
    }

    /**
     * The correct way to embed a raw password that contains '%12' literally
     * is to percent-encode it first via DsnPasswordEncoder::encode().
     */
    public function testLiteralPercentPlusHexHandledCorrectlyViaBuildUrl(): void
    {
        $rawPassword = 'abcdefghixyz%123567890'; // The actual failing test case
        $url = DsnPasswordEncoder::buildUrl('postgres', 'postgres', $rawPassword, 'localhost', 5432, 'db');
        $r = DsnParser::parse($url);
        $this->assertSame(
            $rawPassword,
            $r['password'],
            'buildUrl correctly encodes literal % as %25, so %12 becomes %2512 in the URL.'
        );
    }

    /**
     * Manually constructing the URL with %25 for '%' also works correctly.
     */
    public function testManualPercentTwentyFiveEncodingWorks(): void
    {
        // Raw password: 'abc%12xyz'  →  embed as 'abc%2512xyz' in URL
        $url = 'postgres://user:abc%2512xyz@localhost:5432/db';
        $r = DsnParser::parse($url);
        $this->assertSame(
            'abc%12xyz',
            $r['password'],
            'Writing %25 for literal % in the URL correctly yields the original password.'
        );
    }

    /**
     * A '%' at end of string (not followed by two hex digits) is treated as a
     * literal '%' character by rawurldecode — and our normalizer preserves this.
     */
    public function testBarePercentAtEndPreservedInRawUrl(): void
    {
        $url = 'postgres://user:mypassword%@localhost:5432/db';
        $r = DsnParser::parse($url);
        $this->assertSame('mypassword%', $r['password']);
    }

    /**
     * '%' followed by a non-hex character is invalid percent-encoding and
     * is left as-is by rawurldecode, then correctly encoded by normalizeUrl.
     */
    public function testPercentFollowedByNonHexPreservedInRawUrl(): void
    {
        $url = 'postgres://user:abc%GGxyz@localhost:5432/db';
        $r = DsnParser::parse($url);
        $this->assertSame('abc%GGxyz', $r['password']);
    }

    // =========================================================================
    // 7. buildUrl() sanity checks
    // =========================================================================

    public function testBuildUrlStructure(): void
    {
        $url = DsnPasswordEncoder::buildUrl('postgres', 'alice', 'p@ss#123', 'db.example.com', 5432, 'mydb');
        $this->assertStringStartsWith('postgres://', $url);
        $this->assertStringContainsString('@db.example.com:5432/', $url);
        $this->assertStringContainsString('alice:', $url);
        $this->assertStringNotContainsString('p@ss', $url); // raw @ must be encoded
    }

    public function testBuildUrlWithQuery(): void
    {
        $url = DsnPasswordEncoder::buildUrl(
            'postgres', 'user', 'pass', 'host', 5432, 'db',
            ['sslmode' => 'require', 'pgbouncer' => 'true']
        );
        $this->assertStringContainsString('sslmode=require', $url);
        $this->assertStringContainsString('pgbouncer=true', $url);
    }

    public function testBuildUrlRoundTripWithQuery(): void
    {
        $rawPass = 'p@ss#word?test/path%100';
        $url = DsnPasswordEncoder::buildUrl(
            'postgres', 'myuser', $rawPass, 'db.supabase.co', 5432, 'postgres',
            ['sslmode' => 'require']
        );
        $r = DsnParser::parse($url);
        $this->assertSame($rawPass, $r['password']);
        $this->assertSame('myuser', $r['user']);
        $this->assertSame('require', $r['sslmode']);
    }

    // =========================================================================
    // 8. Real-world scenarios
    // =========================================================================

    public function testSupabaseLegacyUrlWithCleanPassword(): void
    {
        $url = DsnPasswordEncoder::buildUrl(
            'postgresql', 'postgres', 'x67V8RRf3QvhHcPE',
            'db.zfjldiglmcwojzdtxbky.supabase.co', 5432, 'postgres'
        );
        $r = DsnParser::parse($url);
        $this->assertSame('x67V8RRf3QvhHcPE', $r['password']);
        $this->assertSame('require', $r['sslmode']); // Supabase heuristic
    }

    public function testSupabaseUrlWithPercentInPasswordViaBuildUrl(): void
    {
        // Reproduces the user-reported failure — password contains literal '%12'
        $rawPass = 'abcdefghixyz%123567890';
        $url = DsnPasswordEncoder::buildUrl(
            'postgresql', 'postgres', $rawPass,
            'db.kogeaniuoiiwxsnkksvf.supabase.co', 5432, 'postgres'
        );
        $r = DsnParser::parse($url);
        $this->assertSame($rawPass, $r['password']);
        $this->assertSame('require', $r['sslmode']);
    }

    public function testMysqlUrlWithRawSpecialCharsInPassword(): void
    {
        $rawPass = 'my#secret@db?pass';
        $url = "mysql://root:{$rawPass}@127.0.0.1:3306/mydb";
        $r = DsnParser::parse($url);
        $this->assertSame($rawPass, $r['password']);
        $this->assertSame('mysql', $r['driver']);
    }

    public function testPreEncodedPasswordStillDecodesCorrectly(): void
    {
        // User manually encodes their password before putting it in the URL.
        // The normalizer's decode→encode round-trip must not double-encode.
        $rawPass   = 'p@ss#word';
        $preEncoded = rawurlencode($rawPass);  // 'p%40ss%23word'
        $url = "postgres://user:{$preEncoded}@localhost:5432/db";
        $r = DsnParser::parse($url);
        $this->assertSame($rawPass, $r['password']);
    }

    public function testSupabasePoolerUrlWithHashPassword(): void
    {
        $rawPass = 'SecretP#ssw0rd!';
        $url = DsnPasswordEncoder::buildUrl(
            'postgres', 'postgres.projref', $rawPass,
            'aws-0-us-east-1.pooler.supabase.com', 6543, 'postgres'
        );
        $r = DsnParser::parse($url);
        $this->assertSame($rawPass, $r['password']);
        $this->assertTrue($r['pgbouncer']); // port 6543 → pgbouncer
    }
}
