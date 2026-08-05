<?php

declare(strict_types=1);

final class OltTelnet
{
    private string $error = '';

    public function getError(): string
    {
        return $this->error;
    }

    public function runCommand(
        string $host,
        int $port,
        string $username,
        string $password,
        string $command,
        int $timeout = 8
    ): string {
        return $this->runCommands($host, $port, $username, $password, [$command], $timeout);
    }

    public function runCommands(
        string $host,
        int $port,
        string $username,
        string $password,
        array $commands,
        int $timeout = 8
    ): string {
        $this->error = '';
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!is_resource($socket)) {
            $this->error = "Gagal konek ke OLT: {$errstr} ({$errno})";
            return '';
        }

        stream_set_timeout($socket, $timeout);

        try {
            fwrite($socket, "\r\n");
            $output = $this->readUntil($socket, ['login:', 'username:', 'user name:', 'user:', 'name:'], min(3, $timeout));
            fwrite($socket, $username . "\r\n");

            $output .= $this->readUntil($socket, ['password:'], $timeout);
            if (!$this->containsAny($output, ['password:'])) {
                $this->error = 'OLT tidak menampilkan prompt password telnet.';
                return '';
            }

            fwrite($socket, $password . "\r\n");

            $loginOutput = $this->readUntilPrompt($socket, $timeout, $password);
            $output .= $loginOutput;

            if (!$this->hasPrompt($loginOutput)) {
                $this->error = 'Login telnet OLT gagal atau timeout saat menunggu prompt.';
                return '';
            }

            foreach ($commands as $command) {
                $command = trim((string) $command);

                if ($command === '') {
                    continue;
                }

                fwrite($socket, $command . "\r\n");
                $commandOutput = $this->readUntilPrompt($socket, $timeout, $password);
                $output .= $commandOutput;

                if (!$this->hasPrompt($commandOutput)) {
                    $this->error = "Command telnet OLT timeout: {$command}";
                    return '';
                }
            }

            fwrite($socket, "exit\r\n");
        } finally {
            fclose($socket);
        }

        return $this->stripAnsiEscapes($this->stripTelnetNegotiation($output));
    }

    private function readUntil($socket, array $needles, int $timeout): string
    {
        $buffer = '';
        $deadline = time() + $timeout;

        while (time() <= $deadline && !feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk !== false && $chunk !== '') {
                $this->respondTelnetNegotiation($socket, $chunk);
                $buffer .= $chunk;

                if ($this->containsAny($buffer, $needles)) {
                    return $buffer;
                }
            }

            usleep(100000);
        }

        return $buffer;
    }

    /**
     * Membaca output dari OLT sampai muncul prompt (#/>/$).
     * Menangani password tambahan dan pagination OLT.
     *
     * @param resource $socket
     */
    private function readUntilPrompt($socket, int $timeout, string $password = ''): string
    {
        $buffer        = '';
        $deadline      = time() + $timeout;
        $paginateCount = 0;
        $maxPaginate   = 300;
        $sentPostLoginEnter = false;

        while (time() <= $deadline && !feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk === false || $chunk === '') {
                usleep(100000);
                continue;
            }

            $this->respondTelnetNegotiation($socket, $chunk);
            $buffer .= $chunk;
            $chunkPlain = $this->cleanOutput($chunk);

            if (
                !$sentPostLoginEnter
                && preg_match('/Revision\s*:\s*v?7\.75|Chassis\s*:\s*EPON|SN\s*:/i', $this->cleanOutput($buffer)) === 1
            ) {
                fwrite($socket, "\r\n");
                $sentPostLoginEnter = true;
                $deadline = time() + $timeout;
                continue;
            }

            if ($password !== '' && preg_match('/(?:Access|Enable)?\s*Password\s*:/i', $chunkPlain) === 1) {
                fwrite($socket, $password . "\r\n");
                $deadline = time() + $timeout;
                continue;
            }

            if (preg_match('/Access\s+Verification/i', $chunkPlain) === 1) {
                fwrite($socket, "\r\n");
                $deadline = time() + $timeout;
                continue;
            }

            if (
                $paginateCount < $maxPaginate
                && preg_match('/---\s*Enter\s+Key\s+To\s+Continue\s*---?/i', $chunkPlain) === 1
            ) {
                fwrite($socket, "\r\n");
                $deadline = time() + $timeout;
                $paginateCount++;
                continue;
            }

            if (
                $paginateCount < $maxPaginate
                && preg_match('/--[Mm]ore--|---\s*[Mm]ore\s*---/', $chunkPlain) === 1
            ) {
                fwrite($socket, ' ');
                $deadline = time() + $timeout;
                $paginateCount++;
                continue;
            }

            if ($this->hasPrompt($buffer)) {
                return $buffer;
            }
        }

        return $buffer;
    }

    private function respondTelnetNegotiation($socket, string $chunk): void
    {
        $length = strlen($chunk);

        for ($i = 0; $i < $length - 2; $i++) {
            if (ord($chunk[$i]) !== 255) {
                continue;
            }

            $command = ord($chunk[$i + 1]);
            $option = $chunk[$i + 2];

            if ($command === 251 || $command === 252) {
                fwrite($socket, chr(255) . chr(254) . $option);
                $i += 2;
                continue;
            }

            if ($command === 253 || $command === 254) {
                fwrite($socket, chr(255) . chr(252) . $option);
                $i += 2;
            }
        }
    }
    private function containsAny(string $output, array $needles): bool
    {
        $lower = strtolower($this->cleanOutput($output));

        foreach ($needles as $needle) {
            if (str_contains($lower, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    private function hasPrompt(string $output): bool
    {
        $plain = trim($this->cleanOutput($output));
        return preg_match('/(?:^|\R)[\w.-]+(?:\([^)]+\))*\s*[#>$]\s*$/', $plain) === 1
            || preg_match('/[#>$]\s*$/', $plain) === 1;
    }

    private function cleanOutput(string $value): string
    {
        return $this->stripAnsiEscapes($this->stripTelnetNegotiation($value));
    }

    private function stripTelnetNegotiation(string $value): string
    {
        return preg_replace('/\xFF[\xFB-\xFE]./s', '', $value) ?? $value;
    }

    private function stripAnsiEscapes(string $value): string
    {
        $value = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $value) ?? $value;
        $value = preg_replace('/\x1b\][^\x07]*\x07/', '', $value) ?? $value;
        $value = preg_replace('/\x1b[^[\]@-Z\\-_]/', '', $value) ?? $value;
        $value = preg_replace('/[\x00\x08]+/', '', $value) ?? $value;
        return $value;
    }
}
