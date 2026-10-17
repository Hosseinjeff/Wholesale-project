<?php
declare(strict_types=1);

function shop_mail_configured(): bool
{
    $smtp = shop_config()['smtp'] ?? null;
    return is_array($smtp) && trim((string) ($smtp['from'] ?? '')) !== '';
}

function shop_send_mail(string $to, string $subject, string $text): bool
{
    $smtp = shop_config()['smtp'] ?? [];
    if (!is_array($smtp)) {
        return false;
    }
    $from = trim((string) ($smtp['from'] ?? ''));
    if ($from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $host = trim((string) ($smtp['host'] ?? ''));
    if ($host !== '') {
        return shop_smtp_send($smtp, $to, $subject, $text);
    }
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: <{$from}>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, $encoded, $text, $headers);
}

function shop_smtp_send(array $smtp, string $to, string $subject, string $text): bool
{
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 587);
    $user = (string) ($smtp['user'] ?? '');
    $pass = (string) ($smtp['password'] ?? '');
    $from = trim((string) ($smtp['from'] ?? ''));
    $fromName = (string) ($smtp['from_name'] ?? 'مولوی‌سل');
    $encryption = strtolower((string) ($smtp['encryption'] ?? 'starttls'));
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!is_resource($fp)) {
        return false;
    }
    stream_set_timeout($fp, 15);
    $read = static function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = static function (string $command) use ($fp, $read): string {
        fwrite($fp, $command . "\r\n");
        return $read();
    };
    $ok = static function (string $reply, string $expect): bool {
        return str_starts_with($reply, $expect);
    };
    $greet = $read();
    if (!$ok($greet, '220')) {
        fclose($fp);
        return false;
    }
    $cmd('EHLO molavisale');
    if ($encryption === 'starttls') {
        if (!$ok($cmd('STARTTLS'), '220') || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $cmd('EHLO molavisale');
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        if (!$ok($cmd(base64_encode($pass)), '235')) {
            fclose($fp);
            return false;
        }
    }
    if (!$ok($cmd('MAIL FROM:<' . $from . '>'), '250') || !$ok($cmd('RCPT TO:<' . $to . '>'), '250') || !$ok($cmd('DATA'), '354')) {
        fclose($fp);
        return false;
    }
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $body = str_replace(["\r\n", "\r"], "\n", $text);
    $body = str_replace("\n.", "\n..", $body);
    $payload = "From: {$encodedName} <{$from}>\r\nTo: <{$to}>\r\nSubject: {$encodedSubject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
    $payload .= str_replace("\n", "\r\n", $body);
    fwrite($fp, $payload . "\r\n.\r\n");
    $accepted = $ok($read(), '250');
    $cmd('QUIT');
    fclose($fp);
    return $accepted;
}
