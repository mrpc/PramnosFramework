<?php
/**
 * A fixture that redirects, so the follow logic can be exercised over a real socket.
 *
 * `?to=` is the destination. Nothing here validates it on purpose: what is under test is
 * `OutboundUrl`'s own decision about whether to follow, and a fixture that refused a bad
 * destination would be making that decision instead of the code.
 *
 * Served only from the test container's fixture directory and reachable only from the container
 * itself, so there is nothing here for anybody else to point anywhere.
 */
$to = $_GET['to'] ?? '/tests/fixtures/outbound/hello.txt';
header('Location: ' . $to, true, 302);
