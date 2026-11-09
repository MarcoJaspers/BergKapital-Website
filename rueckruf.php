<?php
/**
 * Rückruf-Formular: nimmt die Anfrage entgegen und schickt sie per SMTP über
 * ein Absender-Postfach bei netcup an das Postfach bei Google Workspace.
 *
 * Die Zugangsdaten liegen bewusst NICHT im Repository, sondern in
 * rueckruf-config.php eine Ebene über dem Web-Ordner (siehe unten).
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

const RUECKRUF_MAX_PRO_STUNDE = 5;
const RUECKRUF_ERLAUBTE_ORIGINS = ['https://bergkapital.de', 'https://www.bergkapital.de'];
const RUECKRUF_FEHLER = 'Das hat leider nicht geklappt. Bitte rufen Sie uns an oder schreiben Sie an info@bergkapital.de.';

function antworten(int $status, bool $ok, string $nachricht): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $akzeptiert = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (strpos($akzeptiert, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $nachricht], JSON_UNESCAPED_UNICODE);
    } else {
        // Besucher ohne JavaScript bekommen eine einfache Textseite.
        header('Content-Type: text/plain; charset=utf-8');
        echo $nachricht;
    }
    exit;
}

function feld(string $name): string
{
    $wert = $_POST[$name] ?? '';
    if (!is_string($wert) || preg_match('//u', $wert) !== 1) {
        return '';
    }
    $wert = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $wert);
    return trim(str_replace(["\r\n", "\r"], "\n", $wert));
}

function einzeilig(string $wert): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $wert));
}

/** Begrenzt Anfragen pro IP. Die IP wird nur als Hash und höchstens eine Stunde gehalten. */
function limit_erreicht(string $geheimnis): bool
{
    $datei = dirname(__DIR__) . '/rueckruf-limit.json';
    $handle = @fopen($datei, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        error_log('rueckruf: Limit-Datei nicht nutzbar');
        return false;
    }

    $jetzt = time();
    $daten = json_decode((string) stream_get_contents($handle), true);
    $daten = is_array($daten) ? $daten : [];

    foreach ($daten as $schluessel => $zeiten) {
        $zeiten = array_values(array_filter((array) $zeiten, function ($zeit) use ($jetzt) {
            return is_int($zeit) && $zeit > $jetzt - 3600;
        }));
        if ($zeiten === []) {
            unset($daten[$schluessel]);
        } else {
            $daten[$schluessel] = $zeiten;
        }
    }

    $schluessel = hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $geheimnis);
    $zeiten = $daten[$schluessel] ?? [];
    $erreicht = count($zeiten) >= RUECKRUF_MAX_PRO_STUNDE;
    if (!$erreicht) {
        $zeiten[] = $jetzt;
        $daten[$schluessel] = $zeiten;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($daten));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $erreicht;
}

/** @param resource $verbindung */
function smtp_erwarten($verbindung, int ...$codes): void
{
    $letzte = '';
    while (($zeile = fgets($verbindung, 1024)) !== false) {
        $letzte = $zeile;
        if (strlen($zeile) < 4 || $zeile[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($letzte, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('Unerwartete SMTP-Antwort ' . $code);
    }
}

/** @param resource $verbindung */
function smtp_befehl($verbindung, string $befehl, int ...$codes): void
{
    fwrite($verbindung, $befehl . "\r\n");
    smtp_erwarten($verbindung, ...$codes);
}

function mail_senden(string $server, string $benutzer, string $passwort, string $an, ?string $antwortAn, string $text): void
{
    $kontext = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $verbindung = @stream_socket_client('ssl://' . $server . ':465', $fehlerNr, $fehlerText, 15, STREAM_CLIENT_CONNECT, $kontext);
    if ($verbindung === false) {
        throw new RuntimeException('SMTP-Verbindung fehlgeschlagen: ' . $fehlerNr);
    }
    stream_set_timeout($verbindung, 15);

    try {
        smtp_erwarten($verbindung, 220);
        smtp_befehl($verbindung, 'EHLO bergkapital.de', 250);
        smtp_befehl($verbindung, 'AUTH LOGIN', 334);
        smtp_befehl($verbindung, base64_encode($benutzer), 334);
        smtp_befehl($verbindung, base64_encode($passwort), 235);
        smtp_befehl($verbindung, 'MAIL FROM:<' . $benutzer . '>', 250);
        smtp_befehl($verbindung, 'RCPT TO:<' . $an . '>', 250, 251);
        smtp_befehl($verbindung, 'DATA', 354);

        $kopf = [
            'From: BergKapital Website <' . $benutzer . '>',
            'To: <' . $an . '>',
            'Subject: =?UTF-8?B?' . base64_encode('Neue Rückrufbitte über die Website') . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@bergkapital.de>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($antwortAn !== null) {
            $kopf[] = 'Reply-To: <' . $antwortAn . '>';
        }

        $inhalt = chunk_split(base64_encode(str_replace("\n", "\r\n", $text)), 76, "\r\n");
        smtp_befehl($verbindung, implode("\r\n", $kopf) . "\r\n\r\n" . $inhalt . '.', 250);
        fwrite($verbindung, "QUIT\r\n");
    } finally {
        fclose($verbindung);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    antworten(405, false, 'Nur POST erlaubt.');
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && !in_array($origin, RUECKRUF_ERLAUBTE_ORIGINS, true)) {
    antworten(403, false, RUECKRUF_FEHLER);
}

// Honigtopf: Das Feld ist für Menschen unsichtbar. Bots bekommen eine Erfolgsmeldung, es wird nichts versendet.
if (feld('website') !== '') {
    antworten(200, true, 'Vielen Dank! Wir melden uns bei Ihnen.');
}

$name = einzeilig(feld('Name'));
$telefon = einzeilig(feld('Telefon'));
$email = einzeilig(feld('E-Mail'));
$erreichbarkeit = einzeilig(feld('Erreichbarkeit'));
$anliegen = feld('Anliegen');

if ($name === '' || strlen($name) > 200) {
    antworten(422, false, 'Bitte geben Sie Ihren Namen an.');
}
if (preg_match('/^[0-9+()\/ .\-]{5,40}$/', $telefon) !== 1) {
    antworten(422, false, 'Bitte geben Sie eine gültige Telefonnummer an.');
}
if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    antworten(422, false, 'Bitte prüfen Sie Ihre E-Mail-Adresse.');
}
if (strlen($erreichbarkeit) > 300 || strlen($anliegen) > 5000) {
    antworten(422, false, 'Ihre Angaben sind zu lang. Bitte kürzen Sie den Text.');
}
if (feld('Einwilligung') !== 'ja') {
    antworten(422, false, 'Bitte bestätigen Sie die Einwilligung.');
}

$konfigDatei = dirname(__DIR__) . '/rueckruf-config.php';
$konfig = is_file($konfigDatei) ? require $konfigDatei : null;
if (!is_array($konfig) || empty($konfig['smtp_host']) || empty($konfig['smtp_user']) || empty($konfig['smtp_pass']) || empty($konfig['to'])) {
    error_log('rueckruf: Konfiguration fehlt oder ist unvollständig');
    antworten(503, false, RUECKRUF_FEHLER);
}

$passwort = (string) $konfig['smtp_pass'];

if (limit_erreicht($passwort)) {
    antworten(429, false, 'Es wurden zu viele Anfragen gesendet. Bitte versuchen Sie es später erneut oder rufen Sie uns an.');
}

$text = "Neue Rückrufbitte über bergkapital.de\n\n"
    . 'Name: ' . $name . "\n"
    . 'Telefon: ' . $telefon . "\n"
    . 'E-Mail: ' . ($email !== '' ? $email : '-') . "\n"
    . 'Erreichbarkeit: ' . ($erreichbarkeit !== '' ? $erreichbarkeit : '-') . "\n\n"
    . "Anliegen:\n" . ($anliegen !== '' ? $anliegen : '-') . "\n\n"
    . "Einwilligung zur Bearbeitung der Anfrage: ja\n"
    . 'Eingegangen: ' . date('d.m.Y H:i') . " Uhr\n";

try {
    mail_senden((string) $konfig['smtp_host'], (string) $konfig['smtp_user'], $passwort, (string) $konfig['to'], $email !== '' ? $email : null, $text);
} catch (Throwable $fehler) {
    // Nur die technische Ursache protokollieren, keine Angaben aus dem Formular.
    error_log('rueckruf: ' . $fehler->getMessage());
    antworten(502, false, RUECKRUF_FEHLER);
}

antworten(200, true, 'Vielen Dank! Ihre Anfrage ist bei uns eingegangen. Wir melden uns bei Ihnen.');
