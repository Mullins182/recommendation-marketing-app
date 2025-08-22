<?php
declare(strict_types=1);

/**
 * API (JSON only) mit robuster Fehlerbehandlung
 * - Alle Antworten im JSON-Format
 * - CSRF-Token ohne DB-Init
 * - Globale Handler für Errors/Exceptions -> sauberes JSON
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/utils.php'; // Funktionen (jsonResponse, appUrl, ...)

use App\Database;
use App\Crypto;
use App\Mailer;
use App\Voucher;
use App\Csrf;
use function App\jsonResponse;
use function App\appUrl;

// ---------- JSON-Only Safety ----------
@ini_set('display_errors', '0');            // keine HTML-Fehlerausgabe
header('Content-Type: application/json; charset=utf-8');

// PHP-Errors in Exceptions verwandeln (für konsistentes JSON)
set_error_handler(function ($severity, $message, $file, $line) {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// Ungefangene Exceptions -> JSON
set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Unhandled exception',
        'type'    => get_class($e),
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// ---------- Routing: leichte GETs vorziehen ----------
Csrf::ensureSession();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// 1) CSRF-Token ohne DB
if ($method === 'GET' && $action === 'csrf') {
    jsonResponse(['csrf' => Csrf::token()]);
}

// ---------- Initialisierung (DB/Krypto/Mailer) ----------
try {
    $db     = new Database();
    $pdo    = $db->pdo();
    $crypto = new Crypto();
    $mailer = new Mailer($pdo);
} catch (\Throwable $e) {
    jsonResponse([
        'error'   => 'Backend init failed',
        'type'    => get_class($e),
        'message' => $e->getMessage(),
    ], 500);
}

// ---------- POST-Actions ----------
if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = $_POST ?? [];
    }

    // CSRF prüfen
    $csrf = $input['csrf'] ?? null;
    Csrf::check($csrf);

    try {
        switch ($action) {
            case 'register':
                handleRegister($pdo, $crypto, $mailer, $input);
                break;

            case 'validate_voucher':
                handleValidateVoucher($pdo, $input);
                break;

            case 'redeem_voucher':
                handleRedeemVoucher($pdo, $input);
                break;

            case 'employee_login':
                handleEmployeeLogin($input);
                break;

            default:
                jsonResponse(['error' => 'Unknown action'], 400);
        }
    } catch (\Throwable $e) {
        jsonResponse([
            'error'   => 'Action failed',
            'type'    => get_class($e),
            'message' => $e->getMessage(),
        ], 500);
    }
}

// Fallback
jsonResponse(['error' => 'Unsupported method'], 405);


// ========================= Handlers =========================

function handleRegister(\PDO $pdo, Crypto $crypto, Mailer $mailer, array $input): void {
    $email = trim(strtolower($input['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Ungültige E-Mail'], 422);
    }

    $referral = $input['ref'] ?? null;

    // Verschlüsseln
    $enc = $crypto->encrypt($email);

    // Referrer ermitteln (falls vorhanden)
    $referrerId = null;
    if ($referral) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ?');
        $stmt->execute([$referral]);
        $referrerId = $stmt->fetchColumn() ?: null;
    }

    // Nutzer anlegen (unique referral_code)
    $refCode = genReferralCode($pdo);
    $stmt = $pdo->prepare('INSERT INTO users (email_enc, email_iv, email_tag, referral_code, referrer_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$enc['ciphertext'], $enc['iv'], $enc['tag'], $refCode, $referrerId]);

    $userId = (int)$pdo->lastInsertId();

    // Empfehlungslink + QR per Mail an Nutzer
    $refUrl = appUrl('index.html') . '?ref=' . urlencode($refCode);
    $qrPath = Voucher::generateReferralQr($refUrl);

    $html = "<p>Danke für deine Registrierung!</p>
             <p>Dein Empfehlungslink: <a href='{$refUrl}'>{$refUrl}</a></p>
             <p>Den QR-Code im Anhang kannst du teilen.</p>";

    $mailer->send($email, 'Dein Empfehlungslink', $html, $qrPath, 'empfehlung_qr.png');
    @unlink($qrPath);

    // Falls über Referral registriert -> Geworbener bekommt Gutschein
    if ($referrerId) {
        $discount = (int)($_ENV['VOUCHER_DISCOUNT_PERCENT'] ?? 10);
        $days     = (int)($_ENV['VOUCHER_EXPIRATION_DAYS'] ?? 365);
        $expires  = (new DateTimeImmutable())->modify("+{$days} days");

        $voucher = Voucher::create($pdo, $userId, $discount, $expires);
        $pdfPath = Voucher::generatePdf($voucher['code'], $discount, $expires, $email);

        $mailer->send($email, 'Dein Willkommensgutschein', "<p>Hier ist dein Gutschein als PDF im Anhang.</p>", $pdfPath, 'gutschein.pdf');
        @unlink($pdfPath);
    }

    jsonResponse(['ok' => true, 'referral_code' => $refCode, 'referral_url' => $refUrl]);
}

function genReferralCode(\PDO $pdo): string {
    while (true) {
        $code = bin2hex(random_bytes(8)); // 16 hex chars
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE referral_code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) return $code;
    }
}

function handleValidateVoucher(\PDO $pdo, array $input): void {
    $code = strtoupper(trim($input['code'] ?? ''));
    if ($code === '') jsonResponse(['error' => 'Code fehlt'], 422);

    $stmt = $pdo->prepare('SELECT v.id, v.user_id, v.discount_percent, v.expires_at, u.referrer_id
                           FROM vouchers v JOIN users u ON v.user_id=u.id
                           WHERE v.code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(['valid' => false, 'reason' => 'Nicht gefunden']);

    if (!empty($row['expires_at']) && new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
        jsonResponse(['valid' => false, 'reason' => 'Abgelaufen']);
    }

    jsonResponse([
        'valid'            => true,
        'voucher_id'       => (int)$row['id'],
        'discount_percent' => (int)$row['discount_percent'],
        'user_id'          => (int)$row['user_id'],
        'referrer_id'      => $row['referrer_id'] ? (int)$row['referrer_id'] : null
    ]);
}

function handleRedeemVoucher(\PDO $pdo, array $input): void {
    $code = strtoupper(trim($input['code'] ?? ''));
    $pin  = $input['pin'] ?? '';
    if ($pin !== ($_ENV['EMPLOYEE_PIN'] ?? '')) {
        jsonResponse(['error' => 'PIN ungültig'], 403);
    }

    // Gültigkeit prüfen (wie validate)
    $stmt = $pdo->prepare('SELECT v.id, v.user_id, v.discount_percent, v.expires_at, u.referrer_id
                           FROM vouchers v JOIN users u ON v.user_id=u.id
                           WHERE v.code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) jsonResponse(['error' => 'Gutschein nicht gefunden'], 404);

    if (!empty($row['expires_at']) && new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
        jsonResponse(['error' => 'Gutschein abgelaufen'], 422);
    }

    // Einlösung protokollieren (Gültigkeit bleibt bestehen!)
    $stmt = $pdo->prepare('INSERT INTO redemptions (voucher_id) VALUES (?)');
    $stmt->execute([(int)$row['id']]);

    // Nach Einlösung: Werber bekommt ebenfalls einen Gutschein (falls vorhanden)
    if (!empty($row['referrer_id'])) {
        $referrerId = (int)$row['referrer_id'];
        $discount   = (int)($_ENV['VOUCHER_DISCOUNT_PERCENT'] ?? 10);
        $days       = (int)($_ENV['VOUCHER_EXPIRATION_DAYS'] ?? 365);
        $expires    = (new DateTimeImmutable())->modify("+{$days} days");

        $voucher = Voucher::create($pdo, $referrerId, $discount, $expires);

        // Referrer-Email entschlüsseln
        $stmt2 = $pdo->prepare('SELECT email_enc, email_iv, email_tag FROM users WHERE id = ?');
        $stmt2->execute([$referrerId]);
        $r = $stmt2->fetch(\PDO::FETCH_ASSOC);
        if ($r) {
            $crypto    = new Crypto();
            $refEmail  = $crypto->decrypt($r['email_enc'], $r['email_iv'], $r['email_tag']);
            $pdfPath   = Voucher::generatePdf($voucher['code'], $discount, $expires, $refEmail);
            $mailer    = new Mailer($pdo);
            $mailer->send($refEmail, 'Dein Empfehlungs-Gutschein', "<p>Danke fürs Empfehlen! Hier ist dein Gutschein.</p>", $pdfPath, 'gutschein.pdf');
            @unlink($pdfPath);
        }
    }

    jsonResponse(['ok' => true, 'discount_percent' => (int)$row['discount_percent']]);
}

function handleEmployeeLogin(array $input): void {
    $pin = $input['pin'] ?? '';
    if ($pin !== ($_ENV['EMPLOYEE_PIN'] ?? '')) {
        jsonResponse(['error' => 'PIN ungültig'], 403);
    }
    jsonResponse(['ok' => true]);
}
