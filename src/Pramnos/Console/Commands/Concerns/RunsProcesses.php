<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Running a long external command and showing that it is alive.
 *
 * Extracted from `Init`, which had the only copy, when `project:setup` needed the same
 * thing — `docker-compose up --build`, `composer install` and `migrate` are the same
 * commands whether a project is being created or an existing checkout is being brought
 * up, and a second implementation of the spinner would have been a second place for the
 * escalation logic to be wrong.
 *
 * The escalation is the part worth keeping in one place. A spinner that spins forever is
 * indistinguishable from a hang, so after `slowStepThreshold` seconds this stops
 * spinning, says how long the step has been running, flushes everything captured so far
 * and streams the rest. That behaviour was written because an image pull hung and there
 * was nothing on screen to say so.
 *
 * A user of this trait must provide `explainDockerFailure()` — it is what turns forty
 * lines of daemon output into a command the reader can run, and what it knows how to
 * recognise is the command's business, not the runner's.
 */
trait RunsProcesses
{
    /**
     * Seconds a spinner step may run before its subprocess output is surfaced live.
     * Guards against a silent, endless spinner when a step hangs. 0 disables escalation.
     */
    public int $slowStepThreshold = 120;

    /** When true, every step is reported rather than run. */
    protected bool $dryRun = false;

    /**
     * Turn a known failure into something the reader can act on.
     *
     * Declared abstract rather than defaulted to a no-op: a command that runs Docker
     * and cannot explain a Docker failure is the situation this exists to prevent, and
     * an empty default would let one be written by accident.
     */
    abstract protected function explainDockerFailure(string $log, OutputInterface $output): void;

    /**
     * Run a shell command with a spinner animation, then show DONE/FAILED.
     *
     * @param bool $alwaysShowOutput When true, captured stdout is printed after the
     *                               spinner line regardless of exit code (useful for
     *                               migration steps, where the per-migration list is
     *                               always informative).
     */
    protected function runProcessWithSpinner(string $command, string $message, OutputInterface $output, bool $alwaysShowOutput = false): int
    {
        // Every external step goes through here — composer, docker-compose,
        // migrations, the docs build — so this is the one place a dry run has to
        // stop. Reported rather than silently skipped: "it did not run composer"
        // is part of what the reader is checking.
        if ($this->dryRun) {
            $output->writeln('  would run: <comment>' . $command . '</comment>');
            return 0;
        }

        $isVerbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        // Always strip the 2>/dev/null redirect so we can capture stderr for error display
        $command = str_replace(' 2>/dev/null', '', $command);

        if ($isVerbose) {
            $output->writeln("<info>$message...</info>");
        } else {
            $output->write("$message ");
        }

        $symbols   = ['/', '-', '\\', '|'];
        $i         = 0;
        $stdoutBuf = '';
        $stderrBuf = '';
        $startTime = microtime(true);

        // Once true, subprocess output is streamed live instead of being
        // buffered until the end. It starts on in verbose mode, and also flips
        // on automatically once a step runs longer than slowStepThreshold — so
        // a hung command (e.g. "docker-compose up --build" stuck pulling an
        // image) becomes diagnosable instead of an endless, silent spinner.
        $liveOutput = $isVerbose;

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            // @codeCoverageIgnoreStart
            // proc_open only fails when the shell is unavailable — never in the test container.
            $output->writeln('<error>FAILED</error>');
            return 1;
            // @codeCoverageIgnoreEnd
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            // Always drain both pipes so buffers can't fill and deadlock the
            // child, and so we have captured output ready to surface on escalation.
            $chunkOut = (string) stream_get_contents($pipes[1]);
            $chunkErr = (string) stream_get_contents($pipes[2]);
            $stdoutBuf .= $chunkOut;
            $stderrBuf .= $chunkErr;

            $elapsed = (int) (microtime(true) - $startTime);

            // Escalate a long-running step to live output. This is what makes a
            // hang observable: after slowStepThreshold seconds we announce the
            // delay, flush everything captured so far, and stream the rest.
            if (!$liveOutput && $this->slowStepThreshold > 0 && $elapsed >= $this->slowStepThreshold) {
                $liveOutput = true;
                $output->write("\r\033[K");
                $output->writeln("<comment>$message is still running after " . $this->formatElapsed($elapsed) . " — showing live output:</comment>");
                $buffered = $stdoutBuf . $stderrBuf;
                if (trim($buffered) !== '') {
                    $output->write($buffered);
                }
            }

            if ($liveOutput) {
                // @codeCoverageIgnoreStart
                // Live streaming only runs under -v or after the slow-step
                // escalation; the normal, fast test path never reaches it.
                if ($chunkOut !== '') $output->write($chunkOut);
                if ($chunkErr !== '') $output->write($chunkErr);
                // @codeCoverageIgnoreEnd
            } else {
                // Spinner carries an elapsed-time counter so the user always
                // sees the step is alive and how long it has been working.
                $output->write("\r\033[K$message " . $symbols[$i % 4] . ' (' . $this->formatElapsed($elapsed) . ')');
            }
            $i++;
            usleep(100_000);
        }

        // Switch to blocking mode before final drain so non-blocking reads
        // don't silently drop data that arrived just as the process exited.
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $remainingOut = stream_get_contents($pipes[1]);
        $remainingErr = stream_get_contents($pipes[2]);

        if ($liveOutput) {
            // @codeCoverageIgnoreStart
            if ($remainingOut) $output->write($remainingOut);
            if ($remainingErr) $output->write($remainingErr);
            // @codeCoverageIgnoreEnd
        } else {
            $stdoutBuf .= (string) $remainingOut;
            $stderrBuf .= (string) $remainingErr;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);

        if ($liveOutput) {
            // live output path only under -v or slow-step escalation
            $output->writeln($exitCode === 0 ? "<info>$message: DONE</info>" : "<error>$message: FAILED (Exit Code: $exitCode)</error>"); // @codeCoverageIgnore
        } else {
            $suffix = $exitCode === 0 ? "<info>DONE</info>" : "<error>FAILED</error>";
            $output->write("\r\033[K$message $suffix\n");

            $showOutput = $alwaysShowOutput || $exitCode !== 0;
            if ($showOutput) {
                // @codeCoverageIgnoreStart
                // This block is only entered when a subprocess fails or alwaysShowOutput=true.
                // Non-Docker tests run composer commands that always exit 0 with
                // alwaysShowOutput=false, so this combined output display is never reached.
                $combined = trim($stdoutBuf . "\n" . $stderrBuf);
                if ($combined !== '') {
                    // Output each line indented, avoiding wrapping everything in
                    // a single <error> tag (which can fail if text contains '<').
                    foreach (explode("\n", $combined) as $line) {
                        if (trim($line) === '') {
                            continue;
                        }
                        if ($exitCode !== 0) {
                            $output->writeln('  <comment>' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($line) . '</comment>');
                        } else {
                            $output->writeln('  ' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($line));
                        }
                    }
                }
                // @codeCoverageIgnoreEnd
            }

            if ($exitCode !== 0) {
                // reached only when a subprocess fails
                // @codeCoverageIgnoreStart
                $this->explainDockerFailure($stdoutBuf . "\n" . $stderrBuf, $output);
                // @codeCoverageIgnoreEnd
            }
        }

        return $exitCode;
    }

    /**
     * Formats an elapsed duration compactly for the spinner counter:
     * "45s" under a minute, "2m05s" beyond.
     */
    protected function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
    }
}
