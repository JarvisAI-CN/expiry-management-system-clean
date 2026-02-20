<?php
/**
 * Lightweight SMTP mailer (no external deps)
 * Supports:
 * - AUTH LOGIN
 * - STARTTLS (smtp_secure=tls)
 * - SSL (smtp_secure=ssl)
 */

function smtp_send_mail(array $cfg): array {
    $required = ['host','port','secure','username','password','from_email','from_name','to','subject','html'];
    foreach ($required as $k) {
        if (!array_key_exists($k, $cfg)) {
            return ['success' => false, 'message' => "missing config: $k"];
        }
    }

    $host = $cfg['host'];
    $port = (int)$cfg['port'];
    $secure = strtolower(trim((string)$cfg['secure'])); // tls|ssl|none
    $user = (string)$cfg['username'];
    $pass = (string)$cfg['password'];

    $fromEmail = (string)$cfg['from_email'];
    $fromName = (string)$cfg['from_name'];
    $to = (string)$cfg['to'];
    $subject = (string)$cfg['subject'];

    $html = (string)$cfg['html'];
    $text = (string)($cfg['text'] ?? strip_tags($html));

    $timeout = (int)($cfg['timeout'] ?? 20);

    $remote = ($secure === 'ssl') ? "ssl://$host" : $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        return ['success' => false, 'message' => "connect failed: $errstr ($errno)"];
    }
    stream_set_timeout($fp, $timeout);

    $read = function() use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^\d{3} /', $line)) break; // last line
        }
        return $data;
    };

    $write = function(string $cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    $expect = function(array $codes, string $context) use ($read) {
        $resp = $read();
        if ($resp === '') return [false, "$context: empty response"];
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            return [false, "$context: unexpected response: $resp"];
        }
        return [true, $resp];
    };

    // banner
    [$ok, $msg] = $expect([220], 'banner');
    if (!$ok) return ['success'=>false, 'message'=>$msg];

    $localHost = gethostname() ?: 'localhost';
    $write("EHLO $localHost");
    [$ok, $ehloResp] = $expect([250], 'EHLO');
    if (!$ok) return ['success'=>false, 'message'=>$ehloResp];

    if ($secure === 'tls') {
        $write('STARTTLS');
        [$ok, $tlsResp] = $expect([220], 'STARTTLS');
        if (!$ok) return ['success'=>false, 'message'=>$tlsResp];

        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return ['success'=>false, 'message'=>'STARTTLS: enable crypto failed'];
        }

        // EHLO again
        $write("EHLO $localHost");
        [$ok, $ehlo2] = $expect([250], 'EHLO after STARTTLS');
        if (!$ok) return ['success'=>false, 'message'=>$ehlo2];
    }

    // AUTH LOGIN
    $write('AUTH LOGIN');
    [$ok, $auth1] = $expect([334], 'AUTH LOGIN');
    if (!$ok) return ['success'=>false, 'message'=>$auth1];

    $write(base64_encode($user));
    [$ok, $auth2] = $expect([334], 'AUTH username');
    if (!$ok) return ['success'=>false, 'message'=>$auth2];

    $write(base64_encode($pass));
    [$ok, $auth3] = $expect([235], 'AUTH password');
    if (!$ok) return ['success'=>false, 'message'=>$auth3];

    // MAIL FROM / RCPT TO
    $write("MAIL FROM:<$fromEmail>");
    [$ok, $mf] = $expect([250], 'MAIL FROM');
    if (!$ok) return ['success'=>false, 'message'=>$mf];

    $write("RCPT TO:<$to>");
    [$ok, $rt] = $expect([250, 251], 'RCPT TO');
    if (!$ok) return ['success'=>false, 'message'=>$rt];

    $write('DATA');
    [$ok, $dataResp] = $expect([354], 'DATA');
    if (!$ok) return ['success'=>false, 'message'=>$dataResp];

    $attachments = $cfg['attachments'] ?? [];

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$fromEmail}>";
    $headers[] = "To: <{$to}>";
    $headers[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');

    $altBoundary = 'alt' . bin2hex(random_bytes(8));

    if (is_array($attachments) && count($attachments) > 0) {
        $mixedBoundary = 'mix' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';

        $body = "--$mixedBoundary
";
        $body .= 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "

";

        // alternative: text + html
        $body .= "--$altBoundary
";
        $body .= "Content-Type: text/plain; charset=UTF-8

" . $text . "

";
        $body .= "--$altBoundary
";
        $body .= "Content-Type: text/html; charset=UTF-8

" . $html . "

";
        $body .= "--$altBoundary--
";

        // attachments
        foreach ($attachments as $att) {
            if (!is_array($att)) continue;
            $filename = $att['filename'] ?? 'attachment.bin';
            $ctype = $att['contentType'] ?? 'application/octet-stream';
            $content = $att['content'] ?? '';
            if ($content === '') continue;

            $body .= "
--$mixedBoundary
";
            $body .= 'Content-Type: ' . $ctype . '; name="' . addslashes($filename) . '"' . "
";
            $body .= 'Content-Transfer-Encoding: base64' . "
";
            $body .= 'Content-Disposition: attachment; filename="' . addslashes($filename) . '"' . "

";
            $body .= chunk_split(base64_encode($content), 76, "
");
        }

        $body .= "
--$mixedBoundary--
";
    } else {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';

        $body = "--$altBoundary
";
        $body .= "Content-Type: text/plain; charset=UTF-8

";
        $body .= $text . "

";
        $body .= "--$altBoundary
";
        $body .= "Content-Type: text/html; charset=UTF-8

";
        $body .= $html . "

";
        $body .= "--$altBoundary--
";
    }

    $message = implode("
", $headers) . "

" . $body;
    // dot-stuffing
    $message = preg_replace('/\r\n\./', "\r\n..", $message);

    fwrite($fp, $message . "\r\n.\r\n");
    [$ok, $queued] = $expect([250], 'message body');
    if (!$ok) return ['success'=>false, 'message'=>$queued];

    $write('QUIT');
    fclose($fp);

    return ['success'=>true, 'message'=>'sent'];
}
