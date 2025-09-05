<?php
declare(strict_types=1);

/**
 * JSON-API mit robuster Fehlerbehandlung & Session-Login für Mitarbeiter
 * - Alle Antworten JSON
 * - CSRF-Token per GET ohne DB
 * - Employee-Login setzt Session; redeem_voucher verlangt Session, nicht PIN
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/utils.php';

use App\Database;
use App\Crypto;
use App\Mailer;
use App\Voucher;
use App\Csrf;
use App\Auth;

set_exception_handler(function(Throwable $e){
    \App\jsonResponse([
        'error'   => 'Serverfehler',
        'type'    => get_class($e),
        'message' => $e->getMessage(),
    ], 500);
});

set_error_handler(function($severity, $message, $file, $line){
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ----------------- Helpers -----------------
/** JSON-Body lesen (leere Bodies → leeres Array) */
function readJsonBody(): array {
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Eindeutigen Referral-Code erzeugen */
function genReferralCode(PDO $pdo, int $len = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $out = '';
        $bytes = random_bytes($len);
        for ($i=0; $i<$len; $i++) $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE referral_code = ?');
        $stmt->execute([$out]);
        $exists = (bool)$stmt->fetchColumn();
    } while ($exists);
    return $out;
}

/** Normalisierte E-Mail (lowercase & trim) */
function normalizeEmail(string $email): string {
    return trim(strtolower($email));
}

/**
 * Deterministischer E-Mail-Fingerprint (für Duplikat-Check).
 * Verwendet HMAC-SHA256 mit geheimem Pepper (EMAIL_HASH_KEY).
 */
function emailFingerprint(string $normalizedEmail): string {
    $key = $_ENV['EMAIL_HASH_KEY'] ?? '';
    if ($key === '') {
        throw new RuntimeException('EMAIL_HASH_KEY is not set');
    }
    return hash_hmac('sha256', $normalizedEmail, $key);
}

// ----------------- Leichte GETs -----------------
Csrf::ensureSession();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($method === 'GET' && $action === 'csrf') {
    \App\jsonResponse(['csrf' => Csrf::token()]);
}

// ----------------- Init (DB/Krypto/Mailer) -----------------
$db = new Database();
$pdo = $db->pdo();
$crypto = new Crypto();
$mailer = new Mailer($pdo);

// ----------------- Routing -----------------
if ($method === 'POST') {
    $input = readJsonBody();
    $csrf  = $input['csrf'] ?? null;
    Csrf::check($csrf);

    switch ($action) {
        case 'register':
            handleRegister($pdo, $crypto, $mailer, $input);
            break;

        case 'validate_voucher':
            handleValidateVoucher($pdo, $input);
            break;

        case 'redeem_voucher':
            // NEU: Session statt PIN
            handleRedeemVoucher($pdo, $input);
            break;

        case 'employee_login':
            handleEmployeeLogin($input);
            break;

        case 'employee_logout':
            Auth::logout();
            \App\jsonResponse(['ok' => true]);
            break;

        default:
            \App\jsonResponse(['error' => 'Unknown action'], 404);
    }
    exit;
}

// Fallback
\App\jsonResponse(['error' => 'Unsupported method'], 405);

// ========================= Handlers =========================

function handleRegister(PDO $pdo, Crypto $crypto, Mailer $mailer, array $input): void
{
    $email = normalizeEmail((string)($input['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        \App\jsonResponse(['error' => 'Ungültige E-Mail'], 422);
    }

    $referral = $input['ref'] ?? null;

    // === NEU: Bereits registriert? (HMAC-Fingerprint vergleichen)
    $finger = emailFingerprint($email);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email_hash = ? LIMIT 1');
    $stmt->execute([$finger]);
    if ($stmt->fetchColumn()) {
        \App\jsonResponse([
            'ok'    => false,
            'error' => 'EMAIL_IN_USE',
            'msg'   => 'Diese E-Mail-Adresse ist bereits registriert.'
        ], 409);
    }

    // E-Mail verschlüsseln (für spätere Kontaktaufnahme)
    $enc = $crypto->encrypt($email);

    // Referrer (falls vorhanden)
    $referrerId = null;
    if ($referral) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ?');
        $stmt->execute([$referral]);
        $referrerId = $stmt->fetchColumn() ?: null;
    }

    // Nutzer anlegen (inkl. email_hash)
    $refCode = genReferralCode($pdo);
    try {
        $stmt = $pdo->prepare('
            INSERT INTO users (email_hash, email_enc, email_iv, email_tag, referral_code, referrer_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$finger, $enc['ciphertext'], $enc['iv'], $enc['tag'], $refCode, $referrerId]);
        $userId = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        // Falls Unique-Constraint auf email_hash greift (Race-Condition)
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
            \App\jsonResponse([
                'ok'    => false,
                'error' => 'EMAIL_IN_USE',
                'msg'   => 'Diese E-Mail-Adresse ist bereits registriert.'
            ], 409);
        }
        throw $e;
    }

    // Empfehlungslink + QR an Nutzer
    $refUrl = \App\appUrl('index.html') . '?ref=' . urlencode($refCode);
    $qrPath = Voucher::generateReferralQr($refUrl);

    $html = "<p>Danke für deine Registrierung!</p>
             <p>Dein Empfehlungslink: <a href='{$refUrl}'>{$refUrl}</a></p>
             <p>Den QR-Code im Anhang kannst du teilen.</p>";

    $mailer->send($email, 'Dein Empfehlungslink', $html, $qrPath, 'empfehlung_qr.png');
    @unlink($qrPath);

    // Falls über Referral registriert -> Geworbener bekommt Willkommens-Gutschein
    if ($referrerId) {
        $discount = (int)($_ENV['VOUCHER_DISCOUNT_PERCENT'] ?? 10);
        $days     = (int)($_ENV['VOUCHER_EXPIRATION_DAYS'] ?? 365);
        $expires  = (new DateTimeImmutable())->modify("+{$days} days");

        $voucher = Voucher::create($pdo, $userId, $discount, $expires);
        $pdfPath = Voucher::generatePdf($voucher['code'], $discount, $expires, $email);

        $mailer->send($email, 'Dein Willkommensgutschein', "<p>Herzlichen Glückwunsch! Hier ist dein Gutschein als PDF im Anhang.</p>", $pdfPath, 'gutschein.pdf');
        @unlink($pdfPath);
    }

    \App\jsonResponse(['ok' => true, 'referral_url' => $refUrl]);
}

function handleValidateVoucher(PDO $pdo, array $input): void
{
    $code = strtoupper(trim($input['code'] ?? ''));
    if ($code === '') \App\jsonResponse(['error' => 'Code fehlt'], 422);

    $stmt = $pdo->prepare(
        'SELECT
             v.id,
             v.user_id,
             v.discount_percent,
             v.expires_at,
             u.referrer_id,
             EXISTS (
                 SELECT 1
                 FROM redemptions r
                 WHERE r.voucher_id = v.id
             ) AS already_redeemed,
             (
                 SELECT MAX(r2.redeemed_at)
                 FROM redemptions r2
                 WHERE r2.voucher_id = v.id
             ) AS redeemed_at
         FROM vouchers v
         JOIN users u ON v.user_id = u.id
         WHERE v.code = ?'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        \App\jsonResponse(['valid' => false, 'reason' => 'Nicht gefunden']);
    }

    if (!empty($row['expires_at']) && new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
        \App\jsonResponse(['valid' => false, 'reason' => 'Abgelaufen']);
    }

    if (!empty($row['already_redeemed'])) {
        \App\jsonResponse([
            'valid'  => false,
            'reason' => 'Gutschein wurde bereits am ' . date('d.m.Y H:i', strtotime($row['redeemed_at'])) . ' Uhr eingelöst.',
        ]);
    }

    \App\jsonResponse([
        'valid'            => true,
        'discount_percent' => (int)$row['discount_percent'],
    ]);
}

function handleRedeemVoucher(PDO $pdo, array $input): void
{
    // <<< NEU: Session-Pflicht statt PIN
    Auth::requireEmployee();

    $code = strtoupper(trim($input['code'] ?? ''));
    if ($code === '') \App\jsonResponse(['error' => 'Code fehlt'], 422);

    // Gültigkeit prüfen
    $stmt = $pdo->prepare('SELECT v.id, v.user_id, v.discount_percent, v.expires_at, u.referrer_id
                           FROM vouchers v JOIN users u ON v.user_id=u.id
                           WHERE v.code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) \App\jsonResponse(['error' => 'Gutschein nicht gefunden'], 404);

    if (!empty($row['expires_at']) && new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
        \App\jsonResponse(['error' => 'Gutschein abgelaufen'], 422);
    }

    // Einlösung protokollieren
    $stmt = $pdo->prepare('INSERT INTO redemptions (voucher_id) VALUES (?)');
    $stmt->execute([(int)$row['id']]);

    // Werber belohnen
    if (!empty($row['referrer_id'])) {
        $referrerId = (int)$row['referrer_id'];
        $discount   = (int)($_ENV['VOUCHER_DISCOUNT_PERCENT'] ?? 10);
        $days       = (int)($_ENV['VOUCHER_EXPIRATION_DAYS'] ?? 365);
        $expires    = (new DateTimeImmutable())->modify("+{$days} days");

        $voucher = Voucher::create($pdo, $referrerId, $discount, $expires);

        // Referrer-Mail entschlüsseln und senden
        $stmt2 = $pdo->prepare('SELECT email_enc, email_iv, email_tag FROM users WHERE id = ?');
        $stmt2->execute([$referrerId]);
        if ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $crypto = new Crypto();
            $refEmail = $crypto->decrypt($r['email_enc'], $r['email_iv'], $r['email_tag']);
            $pdfPath  = Voucher::generatePdf($voucher['code'], $discount, $expires, $refEmail);
            $mailer   = new Mailer($pdo);
            $mailer->send($refEmail, 'Dein Empfehlungs-Gutschein', "<p>Danke fürs Empfehlen! Hier ist dein Gutschein.</p>", $pdfPath, 'gutschein.pdf');
            @unlink($pdfPath);
        }
    }

    \App\jsonResponse(['ok' => true, 'discount_percent' => (int)$row['discount_percent']]);
}

function handleEmployeeLogin(array $input): void
{
    $pin = $input['pin'] ?? '';
    Auth::loginOrFail($pin); // <<< setzt Session
    \App\jsonResponse(['ok' => true]);
}
