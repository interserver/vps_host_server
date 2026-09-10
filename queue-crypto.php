#!/usr/bin/env php
<?php
/**
 * queue-crypto.php — host-side PSK envelope CLI for the VPS/QS queue protocol.
 *
 * PLAN STEP 5.1 (plan_queue_encrypt.md) — THE single host-side crypto
 * implementation. Installed on hosts as /usr/local/bin/panel-crypt, driven by
 * the vps_cron.sh / qs_cron.sh shims (step 5.2) and bundled into
 * provirted.phar (step 5.3). Self-contained by design: no composer, no
 * autoload, no DB, no network, no superglobals beyond $argv/getenv/STDIO.
 *
 * BYTE-PARITY CONTRACT
 * --------------------
 * Inlines logic with IDENTICAL derive/seal/open semantics to the frozen
 * panel-side core MyAdmin\Queue\QueueCrypto (step 2.1,
 * mystage include/Queue/QueueCrypto.php), written in PHP 7.2-compatible
 * syntax only (plan Notes 2026-09-08 guardrail list, reviewer M1 — those
 * forbidden constructs are not used in this file, not even in prose).
 * The shared golden vectors of step 2.3 (mystage
 * tests/phpunit/unit/Security/QueueCryptoTest.php, commit 96d7ccf737) pin
 * both sides; `--selftest` below embeds those literals BYTE-IDENTICAL.
 *
 * NORMATIVE wire envelope (identical to QueueCrypto):
 *   "Q1." . base64( nonce[12] . tag[16] . ciphertext )
 * sealed with AES-256-GCM via the 8-arg tag-by-ref call shape:
 *   openssl_encrypt($data, 'aes-256-gcm', deriveKey($psk), OPENSSL_RAW_DATA,
 *                   $nonce, $tag, $aad, 16)
 * The key handed to openssl is ALWAYS deriveKey($psk) — never the raw PSK
 * string (openssl silently NUL-pads key material: the silent-divergence
 * footgun this layer hard-gates).
 *
 * Key derivation (INPUT NORMALIZATION PINNED BY GOLDEN VECTOR 1): $psk is
 * hashed as the RAW 64-char lowercase-hex STRING exactly as stored in the DB
 * column ({vps,qs}_masters.*_queue_key char(64) ascii_bin) — never hex-decoded.
 *   sodium_crypto_generichash($psk, 'myadmin-queue-v1', 32)
 *
 * Plaintext semantics split (mirrors the panel exactly):
 *  - HOST REQUESTS use the seal() shape: JSON object of the caller fields
 *    plus {action, v:1, ts:time()}, encoded with
 *    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE (QC_JSON_FLAGS below —
 *    byte-parity with QueueCrypto::JSON_FLAGS, the step 6.2 parity test
 *    depends on it), AAD = the action. ts is REQUIRED: the panel adapter
 *    (step 2.2) replay-dedups on (identity, inner action, ts) and rejects
 *    |now - ts| > 300s. `enc` keeps the payload minimal: action + v + ts
 *    only; stdin is accepted (5.2 pipe shape) but carries no extra fields.
 *  - PANEL RESPONSES use the raw encrypt() shape: encrypt(handlerOutput,
 *    sealKey, AAD = action) with NO JSON wrapper. `dec` therefore decrypts
 *    and emits the exact plaintext bytes (the response is bash sourced by
 *    the caller — byte fidelity is normative, not cosmetic).
 *
 * MODES (argv[1])
 * ---------------
 *   genkey          create-if-absent master key. If the key file EXISTS,
 *                   read + validate + print it byte-identical and NEVER
 *                   regenerate — co-agent convergence (vps_cron.sh and
 *                   provirted on one host always converge on the same key;
 *                   reviewer C1). Else generate 64 lowercase hex chars,
 *                   install atomically with O_EXCL semantics (no empty-file
 *                   window: tmp write + hardlink install; on concurrent
 *                   create, re-read and print the winner's key), mode 0600.
 *                   Works without sodium/aes-256-gcm (CSPRNG only).
 *   enc <action>    stdin accepted for pipe symmetry, argv[2] = action ->
 *                   stdout: sealed request envelope {action, v:1, ts} with a
 *                   FRESH RANDOM NONCE per call (GCM nonce reuse is a
 *                   catastrophic key-recovery event).
 *   dec <action>    stdin = response envelope, argv[2] = action (the AAD) ->
 *                   stdout: exact decrypted plaintext bytes. ALL-OR-NOTHING
 *                   (reviewer m5): stdin fully buffered, ZERO stdout bytes
 *                   unless the envelope opens completely, so
 *                   `panel-crypt dec | bash` can never execute garbage.
 *   --selftest      capability + golden-vector gate (does NOT touch the key
 *                   file): sodium + aes-256-gcm present, golden derive,
 *                   fixed-nonce envelope literal, reverse open, manual
 *                   literal-only open, wrong-AAD and wrong-key rejection.
 *                   Nonzero exit => host runs permanent-legacy (5.2 policy).
 *   (missing/other) usage to stderr, exit 2.
 *
 * EXIT CODES
 * ----------
 *   0 ok
 *   2 malformed input / missing-or-invalid key / capability absent on enc,dec
 *     / usage error  (stdout stays EMPTY)
 *   3 authentication failure: GCM tag mismatch (wrong key or wrong action AAD)
 *   4 --selftest failure (host must stay legacy-only)
 *
 * KEY FILE
 * --------
 *   path = getenv('QUEUE_KEY_FILE') ?: '/etc/myadmin/queue.key'
 *   The env var is the sandbox seam steps 6.2/6.5 use instead of the real
 *   system path (reviewer-coder #5). Content must trim() to /^[0-9a-f]{64}$/
 *   or enc/dec exit 2. Key material is printed ONLY by genkey, never on
 *   stderr. Companion stamp files <keyfile>.retry / <keyfile>.suspect are
 *   owned by the 5.2 shell shims, not by this CLI.
 *
 * LIBRARY SEAM (5.3)
 * ------------------
 * All logic lives in qc_*() global functions; the CLI entrypoint runs ONLY
 * when this file is the invoked main script, so provirted.phar (step 5.3)
 * can require it and call the same functions in-process ("shell-equivalent
 * function calls" — keeps ONE host-side implementation). Defining
 * QC_AS_LIBRARY before require() also suppresses the CLI for explicitness.
 */

// ---------------------------------------------------------------------------
// Wire constants — identical values to MyAdmin\Queue\QueueCrypto (step 2.1)
// ---------------------------------------------------------------------------

/** Envelope marker; a payload starting with this is an encrypted envelope. */
const QC_PREFIX = 'Q1.';

/** Domain-separation context for the BLAKE2b KDF (exactly 16 bytes = generichash key minimum). */
const QC_KDF_CONTEXT = 'myadmin-queue-v1';

/** GCM standard nonce size in bytes (AES-GCM; 24 would be the secretbox size and is WRONG here). */
const QC_NONCE_LEN = 12;

/** GCM authentication tag size in bytes. */
const QC_TAG_LEN = 16;

/** Required PSK length: lowercase hex of 32 random bytes. */
const QC_KEY_HEX_LEN = 64;

/** The only cipher this protocol uses. */
const QC_CIPHER = 'aes-256-gcm';

/**
 * Minimum decoded envelope payload: nonce + tag, with NO floor on the
 * ciphertext.
 *
 * This used to demand one ciphertext byte on top, which silently broke every
 * sealed EMPTY response: an idle get_new_vps / get_queue / server_list returns
 * '', the panel seals it into exactly 28 raw bytes, and the strict floor of 29
 * threw it out as "malformed" — dec exit 2, suspect flag, permanent downgrade
 * to plaintext, on a host whose key was perfectly correct. AES-GCM
 * authenticates a zero-length plaintext exactly as well as a longer one (the
 * 16-byte tag still covers nonce + AAD), so the byte was never buying
 * anything: an envelope that opens is still proof of the key.
 */
const QC_MIN_RAW_LEN = QC_NONCE_LEN + QC_TAG_LEN;

/**
 * JSON flags for the sealed request plaintext, pinned identical to
 * QueueCrypto::JSON_FLAGS so shim and panel produce byte-identical encodings
 * (slashes and unicode are NOT escaped; key order = action, v, ts).
 */
const QC_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

/** Default key file; override with the QUEUE_KEY_FILE env var (sandbox seam). */
const QC_DEFAULT_KEY_PATH = '/etc/myadmin/queue.key';

// Exit codes (documented above).
const QC_EXIT_OK = 0;
const QC_EXIT_MALFORMED = 2;
const QC_EXIT_AUTHFAIL = 3;
const QC_EXIT_SELFTTEST = 4;

// ---------------------------------------------------------------------------
// Golden vectors (step 2.3) — SHARED-CONSTANT CONTRACT: literals embedded
// BYTE-IDENTICAL from mystage tests/phpunit/unit/Security/QueueCryptoTest.php
// (committed 96d7ccf737). If a literal below differs, --selftest exits 4 on
// every host and enrollment stops working; NEVER edit these to "fix" output.
// ---------------------------------------------------------------------------

/** Fixed test PSK: 64 lowercase hex chars (str_repeat('ab',32)); the KDF hashes this string AS STORED. */
const QC_GOLDEN_PSK_HEX = 'abababababababababababababababababababababababababababababababab';

/** Vector 1 — hex of sodium_crypto_generichash(QC_GOLDEN_PSK_HEX, 'myadmin-queue-v1', 32). */
const QC_GOLDEN_DERIVED_KEY_HEX = '4b533b312ecd9d534ab2c8d273b8c1fb212dbfdcb7a001a32ed79a09b50afff2';

/** Vector 2 fixed nonce as hex — 12 bytes / 24 chars (GCM size). */
const QC_GOLDEN_NONCE_HEX = '000102030405060708090a0b';

/** Vector 2 plaintext bytes handed directly to the encrypt primitive. */
const QC_GOLDEN_PLAINTEXT = 'golden-plaintext';

/** Vector 2 additional authenticated data. */
const QC_GOLDEN_AAD = 'golden-aad';

/** Vector 2 expected full envelope, exactly as the encrypt shape must emit it. */
const QC_GOLDEN_ENVELOPE = 'Q1.AAECAwQFBgcICQoLSVcDP4WDnSuT6HnkGbQP5IWOh0un28OlRa11dpaEt54=';

// ---------------------------------------------------------------------------
// Small boundary helpers (fail-loud, early-exit)
// ---------------------------------------------------------------------------

/**
 * Write a diagnostic line to stderr. Key material NEVER flows through here.
 *
 * @param string $message
 * @return void
 */
function qc_stderr($message)
{
    fwrite(STDERR, 'queue-crypto: ' . $message . "\n");
}

/**
 * Is $value exactly 64 lowercase hex characters (the char(64) column form)?
 *
 * @param mixed $value
 * @return bool
 */
function qc_is_key_hex($value)
{
    if (!is_string($value)) {
        return false;
    }
    return preg_match('/^[0-9a-f]{' . QC_KEY_HEX_LEN . '}$/', $value) === 1;
}

/**
 * Resolve the key file path: QUEUE_KEY_FILE env seam, else the system path.
 *
 * @return string
 */
function qc_key_path()
{
    $override = getenv('QUEUE_KEY_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return QC_DEFAULT_KEY_PATH;
}

/**
 * Is aes-256-gcm usable in this openssl build? (lowercase-normalized probe:
 * openssl_get_cipher_methods() casing varies across versions.)
 *
 * @return bool
 */
function qc_cipher_available()
{
    if (!function_exists('openssl_get_cipher_methods')) {
        return false;
    }
    $methods = array_map('strtolower', openssl_get_cipher_methods());
    return in_array(QC_CIPHER, $methods, true);
}

/**
 * Full capability gate for enc/dec: BLAKE2b KDF + AEAD present?
 *
 * @return bool
 */
function qc_crypto_available()
{
    return function_exists('sodium_crypto_generichash')
        && function_exists('openssl_encrypt')
        && function_exists('openssl_decrypt')
        && qc_cipher_available();
}

/**
 * Drain the openssl error queue into a single readable string.
 *
 * @return string
 */
function qc_openssl_errors()
{
    if (!function_exists('openssl_error_string')) {
        return 'no openssl error reporting available';
    }
    $errors = [];
    while ($error = openssl_error_string()) {
        $errors[] = $error;
    }
    return $errors === [] ? 'no openssl error queued' : implode('; ', $errors);
}

// ---------------------------------------------------------------------------
// Crypto core — inline of QueueCrypto derive/encrypt/decrypt (identical semantics)
// ---------------------------------------------------------------------------

/**
 * Derive the 32-byte AES key from a PSK (BLAKE2b, domain-separated).
 *
 * Same call shape the panel pins in golden vector 1: the PSK string AS
 * STORED is hashed; never hex2bin() it first.
 *
 * @param string $psk 64-char lowercase hex PSK
 * @return string raw 32-byte binary key safe to pass to openssl
 */
function qc_derive_key($psk)
{
    return sodium_crypto_generichash($psk, QC_KDF_CONTEXT, 32);
}

/**
 * Encrypt raw data into a Q1. envelope, FAIL LOUD on any failure.
 *
 * Mirrors QueueCrypto::encrypt(): 8-arg tag-by-ref openssl_encrypt with the
 * DERIVED key, fresh caller-supplied nonce (production callers pass
 * random_bytes(QC_NONCE_LEN); the $nonce parameter also serves the fixed
 * golden-vector seam), concat order nonce || tag || ciphertext, QC_PREFIX.
 *
 * @param string $plaintext raw bytes to seal
 * @param string $psk       64-char lowercase hex PSK
 * @param string $aad       additional authenticated data (enc passes the action; '' for raw)
 * @param string $nonce     exactly QC_NONCE_LEN bytes
 * @return string envelope
 * @throws InvalidArgumentException on malformed PSK shape or wrong-size nonce (programmer error)
 * @throws RuntimeException on any openssl failure
 */
function qc_encrypt($plaintext, $psk, $aad, $nonce)
{
    if (!qc_is_key_hex($psk)) {
        throw new InvalidArgumentException('qc_encrypt: PSK must be ' . QC_KEY_HEX_LEN . ' lowercase hex characters (char(64) column value); refusing to use it');
    }
    if (strlen($nonce) !== QC_NONCE_LEN) {
        throw new InvalidArgumentException('qc_encrypt: nonce must be exactly ' . QC_NONCE_LEN . ' bytes');
    }
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, QC_CIPHER, qc_derive_key($psk), OPENSSL_RAW_DATA, $nonce, $tag, $aad, QC_TAG_LEN);
    if (!is_string($ciphertext)) {
        throw new RuntimeException('qc_encrypt: openssl_encrypt(' . QC_CIPHER . ') failed: ' . qc_openssl_errors());
    }
    return QC_PREFIX . base64_encode($nonce . $tag . $ciphertext);
}

/**
 * Parse a Q1. envelope into its raw parts, or null on ANY shape failure.
 *
 * Strict order mirrors QueueCrypto::decrypt(): prefix (strncmp — the 7.2-safe
 * equivalent of the panel's sniff), strict base64 (no whitespace / URL
 * alphabet tolerated), minimum raw length, then the fixed nonce/tag/ct offsets.
 *
 * @param string $envelope candidate envelope
 * @return array|null ['nonce'=>raw12,'tag'=>raw16,'ciphertext'=>raw] or null
 */
function qc_parse_envelope($envelope)
{
    if (!is_string($envelope) || strncmp($envelope, QC_PREFIX, strlen(QC_PREFIX)) !== 0) {
        return null;
    }
    $raw = base64_decode(substr($envelope, strlen(QC_PREFIX)), true);
    if (!is_string($raw) || strlen($raw) < QC_MIN_RAW_LEN) {
        return null;
    }
    return [
        'nonce' => substr($raw, 0, QC_NONCE_LEN),
        'tag' => substr($raw, QC_NONCE_LEN, QC_TAG_LEN),
        'ciphertext' => substr($raw, QC_NONCE_LEN + QC_TAG_LEN),
    ];
}

/**
 * Open an envelope to its plaintext bytes, or null on ANY failure (shape or
 * authentication). Convenience wrapper used by --selftest; the dec command
 * keeps the two failure classes split for its exit-code contract (2 vs 3).
 *
 * @param string $envelope candidate Q1. envelope
 * @param string $psk      64-char lowercase hex PSK
 * @param string $aad      additional authenticated data used at seal time
 * @return string|null exact plaintext bytes, or null if not authentic
 */
function qc_decrypt_open($envelope, $psk, $aad)
{
    if (!qc_is_key_hex($psk)) {
        return null;
    }
    $parts = qc_parse_envelope($envelope);
    if ($parts === null) {
        return null;
    }
    $plaintext = openssl_decrypt($parts['ciphertext'], QC_CIPHER, qc_derive_key($psk), OPENSSL_RAW_DATA, $parts['nonce'], $parts['tag'], $aad);
    return is_string($plaintext) ? $plaintext : null;
}

// ---------------------------------------------------------------------------
// Key-file I/O
// ---------------------------------------------------------------------------

/**
 * Read and parse the key file at $path into a trusted shape at the boundary.
 *
 * @param string $path
 * @return array ['found'=>bool, 'key'=>string|null (validated 64-hex), 'error'=>string|null]
 */
function qc_read_key_file($path)
{
    if (!file_exists($path)) {
        return ['found' => false, 'key' => null, 'error' => null];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return ['found' => true, 'key' => null, 'error' => 'key file exists but is unreadable: ' . $path];
    }
    $key = trim($raw);
    if (!qc_is_key_hex($key)) {
        return ['found' => true, 'key' => null, 'error' => 'key file does not contain ' . QC_KEY_HEX_LEN . ' lowercase hex characters: ' . $path];
    }
    return ['found' => true, 'key' => $key, 'error' => null];
}

/**
 * Load the validated PSK for enc/dec, reporting shape errors on stderr.
 * Never emits key material.
 *
 * @return string|null validated 64-hex PSK, or null (caller exits 2)
 */
function qc_load_key()
{
    $state = qc_read_key_file(qc_key_path());
    if ($state['error'] !== null) {
        qc_stderr($state['error']);
        return null;
    }
    if (!$state['found']) {
        qc_stderr('key file not found: ' . qc_key_path());
        return null;
    }
    return $state['key'];
}

/**
 * Install a fresh key at $path with create-if-absent + atomic semantics.
 *
 * Strategy: O_EXCL temp file (mode 0600 via umask + chmod), then an atomic
 * NO-CLOBBER hardlink into place — readers only ever see a complete file.
 *
 * @param string $path destination key file
 * @param string $dir  parent directory (already ensured)
 * @param string $key  validated 64-hex key to install
 * @return string 'written' (we installed it) | 'converged' (a co-agent beat us) | 'failed'
 */
function qc_write_key_exclusive($path, $dir, $key)
{
    $oldUmask = umask(0077);
    $tmp = $dir . '/' . basename($path) . '.tmp.' . getmypid() . '.' . mt_rand(100000, 999999);
    $handle = @fopen($tmp, 'x');
    if ($handle === false) {
        umask($oldUmask);
        return 'failed';
    }
    $written = fwrite($handle, $key);
    $complete = ($written === strlen($key)) && fflush($handle);
    fclose($handle);
    if (!$complete) {
        @unlink($tmp);
        umask($oldUmask);
        return 'failed';
    }
    @chmod($tmp, 0600);
    umask($oldUmask);

    if (@link($tmp, $path)) {
        @unlink($tmp);
        return 'written';
    }
    clearstatcache(true, $path);
    if (file_exists($path)) {
        // Concurrent co-agent create (O_EXCL/EEXIST race, reviewer C1):
        // their complete key wins; the caller re-reads and prints it.
        @unlink($tmp);
        return 'converged';
    }
    // Hardlinks unusable on this filesystem: last-resort direct O_EXCL
    // create (tiny empty-file window, still never clobbers an existing key).
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        @unlink($tmp);
        return 'failed';
    }
    $written = fwrite($handle, $key);
    $complete = ($written === strlen($key)) && fflush($handle);
    fclose($handle);
    @chmod($path, 0600);
    @unlink($tmp);
    return $complete ? 'written' : 'failed';
}

// ---------------------------------------------------------------------------
// Mode commands
// ---------------------------------------------------------------------------

/**
 * genkey — create-if-absent master key (never regenerates an existing one).
 *
 * @return int exit code
 */
function qc_cmd_genkey()
{
    $path = qc_key_path();
    $existing = qc_read_key_file($path);
    if ($existing['found']) {
        // Create-if-absent contract: an existing file ALWAYS wins, byte-
        // identical, never regenerated (co-agent convergence, reviewer C1).
        // A corrupt/unreadable existing file fails loud instead of being
        // silently replaced (that would desync every other co-agent).
        if ($existing['error'] !== null) {
            qc_stderr('genkey: refusing to overwrite: ' . $existing['error']);
            return QC_EXIT_MALFORMED;
        }
        fwrite(STDOUT, $existing['key'] . "\n");
        return QC_EXIT_OK;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        $oldUmask = umask(0077);
        @mkdir($dir, 0700, true);
        umask($oldUmask);
        clearstatcache(true, $dir);
        if (!is_dir($dir)) {
            qc_stderr('genkey: cannot create key directory: ' . $dir);
            return QC_EXIT_MALFORMED;
        }
    }

    try {
        $key = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        qc_stderr('genkey: random_bytes failed: ' . $exception->getMessage());
        return QC_EXIT_MALFORMED;
    }

    $result = qc_write_key_exclusive($path, $dir, $key);
    if ($result === 'written') {
        fwrite(STDOUT, $key . "\n");
        return QC_EXIT_OK;
    }
    if ($result === 'converged') {
        $winner = qc_read_key_file($path);
        if ($winner['key'] !== null) {
            fwrite(STDOUT, $winner['key'] . "\n");
            return QC_EXIT_OK;
        }
        qc_stderr('genkey: concurrent create detected but winner key file is invalid: ' . $path);
        return QC_EXIT_MALFORMED;
    }
    qc_stderr('genkey: cannot create key file: ' . $path);
    return QC_EXIT_MALFORMED;
}

/**
 * enc — seal stdin-piped request into a Q1. envelope for the given action.
 *
 * Plaintext is the minimal seal shape {action, v:1, ts} (key order pinned by
 * QueueCrypto::seal); AAD = action; FRESH RANDOM NONCE per call. stdin is
 * consumed for pipe symmetry with the 5.2 shims but contributes no extra
 * fields by design.
 *
 * @param string $action inner action (also the AAD)
 * @return int exit code
 */
function qc_cmd_enc($action)
{
    if (!is_string($action) || $action === '') {
        qc_stderr('enc: usage: panel-crypt enc <action> — the action is both the AAD and the sealed inner action');
        return QC_EXIT_MALFORMED;
    }
    if (!qc_crypto_available()) {
        qc_stderr('enc: this PHP lacks sodium_crypto_generichash or ' . QC_CIPHER . ' (run --selftest)');
        return QC_EXIT_MALFORMED;
    }
    $key = qc_load_key();
    if ($key === null) {
        return QC_EXIT_MALFORMED;
    }
    $stdin = stream_get_contents(STDIN);
    if (!is_string($stdin)) {
        qc_stderr('enc: cannot read stdin');
        return QC_EXIT_MALFORMED;
    }
    $plaintext = json_encode(['action' => $action, 'v' => 1, 'ts' => time()], QC_JSON_FLAGS);
    if (!is_string($plaintext)) {
        qc_stderr('enc: sealed payload JSON encoding failed: ' . json_last_error_msg());
        return QC_EXIT_MALFORMED;
    }
    try {
        $envelope = qc_encrypt($plaintext, $key, $action, random_bytes(QC_NONCE_LEN));
    } catch (Throwable $exception) {
        qc_stderr('enc: sealing failed: ' . $exception->getMessage());
        return QC_EXIT_MALFORMED;
    }
    fwrite(STDOUT, $envelope);
    return QC_EXIT_OK;
}

/**
 * dec — open a response envelope from stdin, printing EXACT plaintext bytes.
 *
 * ALL-OR-NOTHING (reviewer m5): stdin is fully buffered and not one byte
 * reaches stdout unless the whole envelope opens successfully, so a piped
 * `panel-crypt dec <action> | bash` can never source partial garbage.
 * Exit 2 = shape/key problem, exit 3 = authentication (GCM tag) failure.
 * Response plaintext is raw bytes (no JSON wrapper) by protocol design.
 *
 * @param string $action the action the response was sealed with (the AAD)
 * @return int exit code
 */
function qc_cmd_dec($action)
{
    if (!is_string($action) || $action === '') {
        qc_stderr('dec: usage: panel-crypt dec <action> — the response action is the AAD');
        return QC_EXIT_MALFORMED;
    }
    if (!qc_crypto_available()) {
        qc_stderr('dec: this PHP lacks sodium_crypto_generichash or ' . QC_CIPHER . ' (run --selftest)');
        return QC_EXIT_MALFORMED;
    }
    $key = qc_load_key();
    if ($key === null) {
        return QC_EXIT_MALFORMED;
    }
    $envelope = stream_get_contents(STDIN);
    if (!is_string($envelope)) {
        qc_stderr('dec: cannot read stdin');
        return QC_EXIT_MALFORMED;
    }
    // Transport whitespace (echo/curl newlines) is stripped before the
    // STRICT parse; anything else inside the body still fails base64.
    $envelope = trim($envelope, " \t\r\n\0\x0B");
    $parts = qc_parse_envelope($envelope);
    if ($parts === null) {
        qc_stderr('dec: malformed envelope (bad ' . QC_PREFIX . ' prefix, non-strict base64, or under ' . QC_MIN_RAW_LEN . ' raw bytes)');
        return QC_EXIT_MALFORMED;
    }
    $plaintext = openssl_decrypt($parts['ciphertext'], QC_CIPHER, qc_derive_key($key), OPENSSL_RAW_DATA, $parts['nonce'], $parts['tag'], $action);
    if (!is_string($plaintext)) {
        qc_stderr('dec: authentication failed — GCM tag mismatch (wrong key or wrong action AAD)');
        return QC_EXIT_AUTHFAIL;
    }
    fwrite(STDOUT, $plaintext);
    return QC_EXIT_OK;
}

/**
 * Report one selftest check outcome (names only — literals stay out of output).
 *
 * @param string $name
 * @param bool $pass
 * @param array $failures accumulated failure names (by reference)
 * @return void
 */
function qc_selftest_check($name, $pass, array &$failures)
{
    $status = $pass ? 'ok' : 'FAIL';
    if (!$pass) {
        $failures[] = $name;
    }
    fwrite(STDOUT, 'selftest: ' . $status . ' - ' . $name . "\n");
}

/**
 * --selftest — capability + golden-vector gate; NEVER touches the key file.
 *
 * Embeds the step 2.3 golden literals byte-identical and asserts the full
 * matrix pinned by the shared-constant contract: KDF parity (raw-hex-string
 * normalization + anti-hex2bin), the fixed-nonce envelope call shape, the
 * reverse open, a literal-only manual open (vector 1 feeding vector 2 with
 * no core function on the key path), and AAD/key authentication binding.
 *
 * @return int 0 when every check passes; 4 on any failure (permanent-legacy)
 */
function qc_cmd_selftest()
{
    $failures = [];

    qc_selftest_check('sodium extension loaded', extension_loaded('sodium'), $failures);
    qc_selftest_check('sodium_crypto_generichash available', function_exists('sodium_crypto_generichash'), $failures);
    qc_selftest_check('openssl extension loaded', extension_loaded('openssl'), $failures);
    qc_selftest_check(QC_CIPHER . ' cipher available', qc_cipher_available(), $failures);
    qc_selftest_check('KDF context literal is 16 bytes', strlen(QC_KDF_CONTEXT) === 16, $failures);
    qc_selftest_check('golden PSK literal is ' . QC_KEY_HEX_LEN . ' lowercase hex', qc_is_key_hex(QC_GOLDEN_PSK_HEX), $failures);
    qc_selftest_check('golden nonce literal decodes to ' . QC_NONCE_LEN . ' bytes', strlen((string) hex2bin(QC_GOLDEN_NONCE_HEX)) === QC_NONCE_LEN, $failures);

    if ($failures !== []) {
        // Capabilities missing: the vector checks would fatal, not fail —
        // report and gate the host into permanent-legacy right here.
        qc_stderr('selftest: ' . count($failures) . ' capability check(s) FAILED — host stays legacy-only');
        return QC_EXIT_SELFTTEST;
    }

    try {
        // Vector 1 — KDF parity (raw hex STRING hashed as stored).
        $derived = qc_derive_key(QC_GOLDEN_PSK_HEX);
        qc_selftest_check('golden vector 1: deriveKey hex matches pinned literal', strlen($derived) === 32 && bin2hex($derived) === QC_GOLDEN_DERIVED_KEY_HEX, $failures);
        qc_selftest_check('golden vector 1b: plain generichash call shape reproduces it', bin2hex(sodium_crypto_generichash(QC_GOLDEN_PSK_HEX, QC_KDF_CONTEXT, 32)) === QC_GOLDEN_DERIVED_KEY_HEX, $failures);
        qc_selftest_check('golden vector 1c: hex-decoded PSK must derive a DIFFERENT key', bin2hex(sodium_crypto_generichash((string) hex2bin(QC_GOLDEN_PSK_HEX), QC_KDF_CONTEXT, 32)) !== QC_GOLDEN_DERIVED_KEY_HEX, $failures);

        // Vector 2 — fixed-nonce envelope emits the pinned literal.
        $envelope = qc_encrypt(QC_GOLDEN_PLAINTEXT, QC_GOLDEN_PSK_HEX, QC_GOLDEN_AAD, (string) hex2bin(QC_GOLDEN_NONCE_HEX));
        qc_selftest_check('golden vector 2: fixed-nonce encrypt reproduces envelope literal', $envelope === QC_GOLDEN_ENVELOPE, $failures);

        // Vector 3 — reverse open of the pinned literal.
        qc_selftest_check('golden vector 3: decrypt of envelope literal returns plaintext literal', qc_decrypt_open(QC_GOLDEN_ENVELOPE, QC_GOLDEN_PSK_HEX, QC_GOLDEN_AAD) === QC_GOLDEN_PLAINTEXT, $failures);

        // Vector 4 — shim-style manual open from the literals only:
        // strict base64, fixed offsets, derived key from vector 1's hex.
        // No qc_encrypt/qc_decrypt_open on this path, mirroring the panel
        // test's reverse-parity seam (a pair of literals that is only self-
        // consistent still fails here).
        $parts = qc_parse_envelope(QC_GOLDEN_ENVELOPE);
        $manual = null;
        if (is_array($parts) && strlen($parts['tag']) === QC_TAG_LEN && bin2hex($parts['nonce']) === QC_GOLDEN_NONCE_HEX) {
            $manual = openssl_decrypt($parts['ciphertext'], QC_CIPHER, (string) hex2bin(QC_GOLDEN_DERIVED_KEY_HEX), OPENSSL_RAW_DATA, $parts['nonce'], $parts['tag'], QC_GOLDEN_AAD);
        }
        qc_selftest_check('golden vector 4: manual literal-only open (nonce at offset 0, tag 16B, derived-key hex)', $manual === QC_GOLDEN_PLAINTEXT, $failures);
        $raw = base64_decode(substr(QC_GOLDEN_ENVELOPE, strlen(QC_PREFIX)), true);
        qc_selftest_check('golden vector 4b: envelope raw = 12 + 16 + len(plaintext) strict base64', is_string($raw) && strlen($raw) === QC_NONCE_LEN + QC_TAG_LEN + strlen(QC_GOLDEN_PLAINTEXT), $failures);

        // Authentication binding — tampered AAD and wrong key must not open.
        qc_selftest_check('golden vector 5: wrong AAD fails the tag check', qc_decrypt_open(QC_GOLDEN_ENVELOPE, QC_GOLDEN_PSK_HEX, QC_GOLDEN_AAD . 'tampered') === null, $failures);
        $wrongKey = str_repeat('00', QC_KEY_HEX_LEN / 2);
        qc_selftest_check('golden vector 6: wrong key fails the tag check', qc_decrypt_open(QC_GOLDEN_ENVELOPE, $wrongKey, QC_GOLDEN_AAD) === null, $failures);
        // Empty plaintext round trip: an idle queue handler returns '', and a
        // sealed '' is exactly QC_NONCE_LEN + QC_TAG_LEN raw bytes. Pinned
        // because a one-byte-too-strict floor here reads as "malformed
        // envelope" and downgrades a correctly-keyed host to plaintext.
        $emptyEnvelope = qc_encrypt('', QC_GOLDEN_PSK_HEX, QC_GOLDEN_AAD, (string) hex2bin(QC_GOLDEN_NONCE_HEX));
        $emptyRaw = base64_decode(substr($emptyEnvelope, strlen(QC_PREFIX)), true);
        qc_selftest_check('empty plaintext seals to exactly nonce+tag raw bytes', is_string($emptyRaw) && strlen($emptyRaw) === QC_NONCE_LEN + QC_TAG_LEN, $failures);
        qc_selftest_check('empty plaintext envelope parses (no ciphertext-length floor)', qc_parse_envelope($emptyEnvelope) !== null, $failures);
        qc_selftest_check('empty plaintext envelope opens back to the empty string', qc_decrypt_open($emptyEnvelope, QC_GOLDEN_PSK_HEX, QC_GOLDEN_AAD) === '', $failures);
        qc_selftest_check('empty plaintext envelope still fails on a wrong key', qc_decrypt_open($emptyEnvelope, $wrongKey, QC_GOLDEN_AAD) === null, $failures);

        // Seal-shape JSON byte-parity: minimal request plaintext must use the
        // pinned flags and action-first key order the panel parser expects.
        $probe = json_encode(['action' => 'x', 'v' => 1, 'ts' => 1757356800], QC_JSON_FLAGS);
        qc_selftest_check('seal JSON shape: {"action":..,"v":1,"ts":..} unescaped', $probe === '{"action":"x","v":1,"ts":1757356800}', $failures);
    } catch (Throwable $exception) {
        $failures[] = 'unexpected throwable: ' . $exception->getMessage();
        qc_stderr('selftest: throwable — ' . $exception->getMessage());
    }

    if ($failures !== []) {
        qc_stderr('selftest: ' . count($failures) . ' check(s) FAILED — host stays legacy-only');
        return QC_EXIT_SELFTTEST;
    }
    fwrite(STDOUT, "selftest: ALL CHECKS PASSED\n");
    return QC_EXIT_OK;
}

// ---------------------------------------------------------------------------
// CLI plumbing
// ---------------------------------------------------------------------------

/**
 * Usage block for the error path (stderr; plain stdout stream for --help).
 *
 * @param resource $stream
 * @return void
 */
function qc_usage($stream)
{
    $usage = <<<'TEXT'
usage: panel-crypt <mode> [args]

  genkey              create-if-absent 64-hex master key at
                      getenv('QUEUE_KEY_FILE') ?: /etc/myadmin/queue.key (0600);
                      prints existing key byte-identical when the file exists
  enc <action>        stdin request data + action -> sealed Q1. envelope
                      (plaintext {action, v:1, ts}, AAD = action)
  dec <action>        stdin Q1. response envelope + action -> exact plaintext
                      bytes on stdout (all-or-nothing; zero output on failure)
  --selftest          capability + golden-vector gate (no key file needed);
                      nonzero exit = run this host in permanent-legacy mode

exit codes: 0 ok | 2 malformed/no-key/usage | 3 authentication failure | 4 selftest failure

TEXT;
    fwrite($stream, $usage);
}

/**
 * Dispatch argv[1] to a mode. Unknown/absent mode: usage to stderr, exit 2.
 *
 * @param array $argv
 * @return int exit code
 */
function qc_main(array $argv)
{
    $mode = isset($argv[1]) ? $argv[1] : '';
    if ($mode === 'genkey') {
        return qc_cmd_genkey();
    }
    if ($mode === 'enc') {
        return qc_cmd_enc(isset($argv[2]) ? $argv[2] : '');
    }
    if ($mode === 'dec') {
        return qc_cmd_dec(isset($argv[2]) ? $argv[2] : '');
    }
    if ($mode === '--selftest') {
        return qc_cmd_selftest();
    }
    if ($mode === 'help' || $mode === '--help') {
        qc_usage(STDOUT);
        return QC_EXIT_OK;
    }
    qc_usage(STDERR);
    return QC_EXIT_MALFORMED;
}

/**
 * Is this file running as the invoked CLI main script (vs required as a
 * library by provirted.phar, step 5.3)?
 *
 * @param array $argv
 * @return bool
 */
function qc_invoked_as_main(array $argv)
{
    if (PHP_SAPI !== 'cli' || defined('QC_AS_LIBRARY')) {
        return false;
    }
    if (strncmp(__FILE__, 'phar://', 7) === 0) {
        return false;
    }
    if (!isset($argv[0])) {
        return false;
    }
    $self = realpath(__FILE__);
    $invoked = realpath($argv[0]);
    if ($self === false || $invoked === false) {
        // Unresolvable paths: only raw textual equality counts — a require
        // from another main script must never accidentally run the CLI.
        return $argv[0] === __FILE__;
    }
    return $invoked === $self;
}

$__qcArgv = isset($argv) && is_array($argv) ? $argv : [];
if (qc_invoked_as_main($__qcArgv)) {
    exit(qc_main($__qcArgv));
}
