<?php

/**
 * A scripted IMAP server for BounceMailboxTest: one connection, the commands the client speaks.
 *
 * php server.php <messages.json> <log> — prints the port it listens on, then serves. The
 * messages file maps UIDs to messages, plus `user`, `password` and `token` to accept; every
 * command received is appended to the log, with the UIDs stored as seen.
 */

[$script, $messagesFile, $log] = $argv;
$config   = json_decode((string) file_get_contents($messagesFile), true);
$server   = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$name     = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);
$client = stream_socket_accept($server, 20);
if ($client === false) {
    exit(1);
}
$say = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};
$say(($config['greeting'] ?? '* OK') . ' fake IMAP ready');
while (($line = fgets($client)) !== false) {
    file_put_contents($log, $line, FILE_APPEND);
    if (!preg_match('/^(\S+) (.*?)\r?\n$/', $line, $m)) {
        continue;
    }
    [$all, $tag, $command] = $m;
    $verb = strtoupper((string) strtok($command, ' '));
    if ($verb === 'LOGIN') {
        $say($command === 'LOGIN "' . addcslashes($config['user'], '"\\') . '" "' . addcslashes($config['password'], '"\\') . '"' ? $tag . ' OK signed in' : $tag . ' NO [AUTHENTICATIONFAILED] Invalid credentials');
    } elseif ($verb === 'AUTHENTICATE') {
        $sasl = base64_decode(substr($command, strlen('AUTHENTICATE XOAUTH2 ')));
        if ($sasl === 'user=' . $config['user'] . "\x01auth=Bearer " . $config['token'] . "\x01\x01") {
            $say($tag . ' OK signed in');
        } else {
            $say('+ ' . base64_encode('{"status":"400"}'));
            fgets($client);
            $say($tag . ' NO [AUTHENTICATIONFAILED] Invalid credentials');
        }
    } elseif ($verb === 'SELECT') {
        $say('* ' . count($config['messages']) . ' EXISTS');
        $say($tag . ' OK [READ-WRITE] selected');
    } elseif ($command === 'UID SEARCH UNSEEN') {
        $say('* SEARCH ' . implode(' ', array_reverse(array_keys($config['messages']))));
        $say($tag . ' OK search done');
    } elseif (preg_match('/^UID FETCH (\d+) \(?BODY\.PEEK\[\]\)?$/', $command, $f)) {
        $body = (string) ($config['messages'][$f[1]] ?? '');
        fwrite($client, '* ' . $f[1] . ' FETCH (UID ' . $f[1] . ' BODY[] {' . strlen($body) . "}\r\n" . $body . ")\r\n");
        $say($tag . ' OK fetched');
    } elseif (preg_match('/^UID STORE (\d+) \+FLAGS(\.SILENT)? \(\\\\Seen\)$/', $command, $f)) {
        $say('* ' . $f[1] . ' FETCH (FLAGS (\Seen))');
        $say($tag . ' OK stored');
    } elseif ($verb === 'LOGOUT') {
        $say('* BYE');
        $say($tag . ' OK bye');
        break;
    } else {
        $say($tag . ' BAD unknown');
    }
}
fclose($client);
