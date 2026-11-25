<?php

declare(strict_types=1);

/*
 * An SMTP server for the sender protocol tests: prints its port, then serves
 * one connection per comma-separated script and logs each received line as
 * "<connection>|<line>". Scripts: helo-fallback (EHLO refused, HELO
 * accepted), plain (no STARTTLS offered), starttls-refused (454),
 * starttls-broken (220, then no handshake), tls (STARTTLS with the given
 * certificate) and implicit (TLS from the first byte).
 */

[, $scripts, $log, $certificate] = $argv + [3 => ''];

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
fwrite(STDOUT, explode(':', (string) stream_socket_get_name($server, false))[1]."\n");
fflush(STDOUT);

$secure = function ($client) use ($certificate): bool {
    stream_context_set_option($client, 'ssl', 'local_cert', $certificate);

    return stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) === true;
};

foreach (explode(',', $scripts) as $index => $script) {
    $connection = $index + 1;
    $client = @stream_socket_accept($server, 10);
    if ($client === false) {
        exit(1);
    }
    stream_set_timeout($client, 10);
    if ($script === 'implicit' && ! $secure($client)) {
        exit(1);
    }
    fwrite($client, "220 smtp.example.test ESMTP\r\n");

    $tls = $script === 'implicit';
    $inData = false;
    while (($line = fgets($client)) !== false) {
        file_put_contents($log, "{$connection}|{$line}", FILE_APPEND);

        if ($inData) {
            if (mb_rtrim($line, "\r\n") === '.') {
                $inData = false;
                fwrite($client, "250 queued\r\n");
            }

            continue;
        }

        $verb = mb_strtoupper(strtok(mb_rtrim($line, "\r\n"), ' :') ?: '');
        $offersTls = in_array($script, ['starttls-refused', 'starttls-broken', 'tls'], true) && ! $tls;
        $reply = match (true) {
            $verb === 'EHLO' && $script === 'helo-fallback' => "502 command not implemented\r\n",
            $verb === 'EHLO' => "250-smtp.example.test\r\n".($offersTls ? "250-STARTTLS\r\n" : '')."250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n",
            $verb === 'STARTTLS' && $script === 'starttls-refused' => "454 TLS not available\r\n",
            $verb === 'STARTTLS' => "220 go ahead\r\n",
            $verb === 'DATA' => "354 go ahead\r\n",
            $verb === 'AUTH' => "235 ok\r\n",
            $verb === 'QUIT' => "221 bye\r\n",
            default => "250 ok\r\n",
        };
        $inData = $verb === 'DATA';
        fwrite($client, $reply);

        if ($verb === 'STARTTLS' && $script === 'starttls-broken') {
            fwrite($client, "this is not a TLS handshake\r\n");
        }
        if ($verb === 'STARTTLS' && $script === 'tls') {
            if (! $secure($client)) {
                break;
            }
            $tls = true;
        }
        if ($verb === 'QUIT') {
            break;
        }
    }
    fclose($client);
}
