<?php
declare(strict_types=1);

namespace App;

use Dompdf\Dompdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use PDO;

class Voucher {
    public static function create(PDO $pdo, int $userId, int $discountPercent, ?\DateTimeImmutable $expires = null): array {
        $code = self::generateCode(24);
        $stmt = $pdo->prepare('INSERT INTO vouchers (user_id, code, discount_percent, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $code, $discountPercent, $expires?->format('Y-m-d H:i:s')]);
        return ['id' => (int)$pdo->lastInsertId(), 'code' => $code];
    }

    public static function generatePdf(string $voucherCode, int $discountPercent, ?\DateTimeImmutable $expires, string $userEmail): string {
    // QR als Data-URI bauen (kein Dateisystem nötig)
    $qr = \Endroid\QrCode\Builder\Builder::create()
        ->data($voucherCode)      // QR enthält NUR den Code; Mitarbeiter-App prüft im Backend
        ->writer(new \Endroid\QrCode\Writer\PngWriter())
        ->size(300)
        ->margin(10)
        ->build();

    $qrDataUri = $qr->getDataUri(); // <- wichtig: direkt als data:image/png;base64,...

    $expiresText = $expires ? $expires->format('d.m.Y') : 'Kein Ablaufdatum';

    $html = <<<HTML
<html><body style="font-family: DejaVu Sans, Arial, sans-serif;">
  <h2 style="margin-bottom:4px">Gutschein</h2>
  <p>Für: {$userEmail}</p>
  <p>Rabatt: <strong>{$discountPercent}%</strong></p>
  <p>Gutscheincode: <strong>{$voucherCode}</strong></p>
  <p>Gültig bis: <strong>{$expiresText}</strong></p>
  <p style="margin-top:20px;">QR-Code zum Einlösen im Geschäft:</p>
  <img src="{$qrDataUri}" alt="Voucher QR" style="width:250px;height:auto;">
  <p style="font-size:12px;color:#666;margin-top:20px">Hinweis: Dieser Gutschein bleibt auch nach Einlösung weiterhin gültig.</p>
</body></html>
HTML;

    // Dompdf mit Option initialisieren (für Sicherheit/Kompatibilität)
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true); // schadet nicht, hilft bei externen Ressourcen
    $dompdf = new \Dompdf\Dompdf($options);

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfPath = sys_get_temp_dir() . '/voucher_' . $voucherCode . '.pdf';
    file_put_contents($pdfPath, $dompdf->output());

    return $pdfPath;
}


    public static function generateReferralQr(string $referralUrl): string {
        $qr = Builder::create()
            ->data($referralUrl)
            ->writer(new PngWriter())
            ->size(300)
            ->margin(10)
            ->build();
        $path = sys_get_temp_dir() . '/ref_' . md5($referralUrl) . '.png';
        $qr->saveToFile($path);
        return $path;
    }

    private static function generateCode(int $length = 24): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O, 1/I
        $bytes = random_bytes($length);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
        }
        return $out;
    }
}