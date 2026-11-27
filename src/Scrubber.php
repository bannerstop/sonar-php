<?php

declare(strict_types=1);

namespace Bannerstop\Sonar;

/**
 * Makes a value safe to send: the value of a field whose name is on the list becomes `[scrubbed]` (the key stays),
 * objects are never dumped, depth and size are bounded, and secrets inside strings are redacted: values of the
 * application's own secret environment variables, strings that are clearly a secret (AWS keys, JWTs, PEM private keys,
 * bearer and basic credentials, credentials in a URL, sonar, GitHub, Slack and Stripe tokens) and `key=value` pairs
 * whose key is secret-like. False positives are fine, a leak is not.
 *
 * Names compare in lower case, letters and digits only (`X-Api-Key` is `xapikey`). A name is secret or personal when
 *
 * - it contains one of CONTAINS (`token`, `password`, `session`, `email`, ...),
 * - it is, starts or ends with one of EDGE (short parts that would hit ordinary words in the middle: `pwd`, `otp`,
 *   `dsn`, `salt`),
 * - it is or ends with one of END (`pass`, `pw`, `auth`, `key`, `keyid`: `DB_PASS`, `APP_KEY`, `accessKeyId`, but not
 *   `keyword`, `cache_key_prefix` or `bypass`; END_EXCEPTIONS lists the ordinary words that end like that, `monkey`),
 * - it is one of EXACT (`name`, `login`, `pin`, ...) or one the application adds (`scrub` option; as a whole).
 *
 * sonar's intake has the same rule (App\Occurrence\Scrubber) and tests/Fixtures/secrets-golden.json is the shared fixture.
 *
 * @internal
 */
final class Scrubber
{
    public const REPLACEMENT = '[scrubbed]';

    private const CONTAINS = [
        'password', 'passwd', 'passphrase', 'passcode', 'passkey', 'secret', 'token', 'apikey', 'authorization', 'authpw', 'authuser', 'authheader', 'authcode',
        'authstring', 'cookie', 'credential', 'private', 'csrf', 'xsrf', 'session', 'sessid', 'signature', 'jwt',
        'email', 'phone', 'firstname', 'lastname', 'fullname', 'username', 'surname', 'creditcard', 'cardnumber', 'iban',
    ];
    private const EDGE = ['pwd', 'otp', 'dsn', 'salt'];
    private const END = ['pass', 'pw', 'auth', 'key', 'keyid'];
    private const END_EXCEPTIONS = ['monkey', 'turkey', 'donkey', 'hockey', 'jockey', 'whiskey', 'lackey', 'compass', 'bypass', 'overpass', 'underpass', 'surpass', 'trespass', 'basalt'];
    private const EXACT = ['name', 'mail', 'tel', 'mobile', 'fax', 'login', 'pin', 'cvv', 'cvc', 'ssn', 'sid', 'card'];

    /** @var list<string> environment variables that look secret but only name a directory */
    private const NOT_SECRET_ENV = ['PWD', 'OLDPWD'];
    private const MIN_SECRET_LENGTH = 8;

    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 50;
    private const MAX_STRING = 500;

    /** the shapes of clearly-secret strings: a pattern, then its replacement */
    private const PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY(?: BLOCK)?-----.*?-----END [A-Z0-9 ]*PRIVATE KEY(?: BLOCK)?-----/s' => self::REPLACEMENT,
        // a block that was cut before its end line goes on to the end of the text
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY(?: BLOCK)?-----.*$/s' => self::REPLACEMENT,
        '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/' => self::REPLACEMENT,
        '/(?:AKIA|ASIA)[A-Z0-9]{16}/' => self::REPLACEMENT,
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{6,}/i' => '$1 '.self::REPLACEMENT,
        '#([a-z][a-z0-9+.-]*://)[^\s/@:]*:[^\s/]*@#i' => '$1'.self::REPLACEMENT.'@',
        '/sonar_[a-z]+_[A-Za-z0-9]+/' => self::REPLACEMENT,
        '/(?:gh[pousr]_[A-Za-z0-9]{10,}|github_pat_[A-Za-z0-9_]{10,})/' => self::REPLACEMENT,
        '/(?:xox[a-z]|xapp)-[A-Za-z0-9-]{8,}/' => self::REPLACEMENT,
        '/(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/' => self::REPLACEMENT,
        // HTTP headers: a cookie header is a list, so the rest of the line goes; an authorization header is a scheme and a credential
        '/\b((?:set-)?cookie)(\s*:\s*)[^\r\n]*/i' => '$1$2'.self::REPLACEMENT,
        '/\b((?:proxy-)?authorization)(\s*:\s*)(?:[A-Za-z]+\s+)?\S+/i' => '$1$2'.self::REPLACEMENT,
    ];

    /** @var array<string, true> */
    private $exact = [];

    /** @var list<string> */
    private $secretValues;

    /**
     * @param list<string> $extraNames    names the application adds
     * @param list<string> $secretValues  values of the application's own secrets (see secretValues()), longest first; kept in memory only
     */
    public function __construct(array $extraNames = [], array $secretValues = [])
    {
        foreach (array_map([self::class, 'normalise'], $extraNames) as $name) {
            $this->exact[$name] = true;
        }
        $this->secretValues = $secretValues;
    }

    /**
     * The values of the environment variables whose name is secret-like (the built-in rule, the same one that scrubs
     * field names) and that are at least 8 characters long, longest first, with their URL-encoded forms.
     *
     * @param list<array<array-key, mixed>> $bags getenv(), $_ENV, $_SERVER, ...
     *
     * @return list<string>
     */
    public static function secretValues(array $bags): array
    {
        $found = [];
        foreach ($bags as $bag) {
            foreach ($bag as $name => $value) {
                if (!is_string($name) || !is_string($value) || strlen($value) < self::MIN_SECRET_LENGTH
                    || in_array(strtoupper($name), self::NOT_SECRET_ENV, true) || !self::isSecretName(self::normalise($name))) {
                    continue;
                }
                foreach ([$value, rawurlencode($value), urlencode($value)] as $form) {
                    if (strlen($form) >= self::MIN_SECRET_LENGTH) {
                        $found[$form] = true;
                    }
                }
            }
        }
        $values = array_map('strval', array_keys($found));
        usort($values, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return $values;
    }

    /**
     * @param mixed $value
     *
     * @return mixed a value json_encode() takes
     */
    public function value($value, int $depth = 0)
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[truncated]';
            }
            $result = [];
            foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $key => $item) {
                $secret = is_string($key) && $this->isSensitive($key);
                $result[is_string($key) ? $this->redact($key) : $key] = $secret ? self::REPLACEMENT : $this->value($item, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            return $this->text($value, self::MAX_STRING);
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (null === $value || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_object($value)) {
            return '[object '.self::className($value).']';
        }

        return '['.get_resource_type($value).' resource]';
    }

    /**
     * Free text cannot be scrubbed by name, so the secrets go (see redact()) and e-mail addresses. Then it is cut to
     * `$max` bytes.
     */
    public function text(string $text, int $max): string
    {
        $text = $this->redact($text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', self::REPLACEMENT, $text);

        return self::cut($text, $max);
    }

    /**
     * Takes the secrets out of any string that is sent, whatever it is: the value of a secret environment variable of
     * the application, a string of a clearly-secret shape and the value of a `key=value` pair with a secret-like key.
     * It does not cut and leaves e-mail addresses (see text()).
     */
    public function redact(string $text): string
    {
        foreach ($this->secretValues as $secret) {
            $text = str_replace($secret, self::REPLACEMENT, $text);
        }
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $this->pairs($text);
    }

    public static function cut(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        $cut = substr($text, 0, $max);
        // never end inside a multibyte character: sonar refuses invalid UTF-8
        while ('' !== $cut && !preg_match('//u', $cut)) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /**
     * @param object $object
     */
    public static function className($object): string
    {
        $class = get_class($object);
        $end = strpos($class, "\0");

        return false === $end ? $class : substr($class, 0, $end);
    }

    /**
     * `key=value`, `key: value`, `key => value` and `"key":"value"` with a secret-like key lose the value (quotes stay).
     * A pair whose key is harmless is not consumed, so a secret-like key inside its value is found as well
     * (`warning: sentry_dsn: x`, `next:password=x`).
     */
    private function pairs(string $text): string
    {
        $key = '/(["\'\[]?)([A-Za-z0-9_.-]+)(["\'\]]?)(\s*(?:=>|[=:])\s*)/';
        $value = '/\G(\[scrubbed\]|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|"[^\r\n]*|\'[^\r\n]*|[^\s,;&"\'\]}]+)/';
        $out = '';
        $pos = 0;
        while (preg_match($key, $text, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $end = $m[0][1] + strlen($m[0][0]);
            $out .= substr($text, $pos, $end - $pos);
            $pos = $end;
            if (!$this->isSensitive($m[2][0]) || !preg_match($value, $text, $v, 0, $end)) {
                continue;
            }
            $quote = $v[1][0];
            $out .= '"' === $quote || "'" === $quote ? $quote.self::REPLACEMENT.(strlen($v[1]) > 1 && substr($v[1], -1) === $quote ? $quote : '') : self::REPLACEMENT;
            $pos = $end + strlen($v[1]);
        }

        return $out.substr($text, $pos);
    }

    private function isSensitive(string $key): bool
    {
        $name = self::normalise($key);

        return isset($this->exact[$name]) || self::isSecretName($name);
    }

    /**
     * @param string $name in normal form
     */
    private static function isSecretName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        if (in_array($name, self::EXACT, true)) {
            return true;
        }
        foreach (self::CONTAINS as $part) {
            if (false !== strpos($name, $part)) {
                return true;
            }
        }
        foreach (self::END_EXCEPTIONS as $word) {
            if (self::endsWith($name, $word)) {
                return false;
            }
        }
        foreach (self::EDGE as $part) {
            if (0 === strpos($name, $part) || self::endsWith($name, $part)) {
                return true;
            }
        }
        foreach (self::END as $part) {
            if (self::endsWith($name, $part)) {
                return true;
            }
        }

        return false;
    }

    private static function endsWith(string $name, string $part): bool
    {
        return substr($name, -strlen($part)) === $part;
    }

    private static function normalise(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
}
