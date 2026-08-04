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
            $output = $this->readUntil($socket, ['login:', 'username:', 'user name:', 'user:', 'name:'], $timeout);
            fwrite($socket, $username . "\r\n");

            $output .= $this->readUntil($socket, ['password:'], $timeout);
            fwrite($socket, $password . "\r\n");

            $output .= $this->readUntilPrompt($socket, $timeout);
            fwrite($socket, $command . "\r\n");

            $output .= $this->readUntilPrompt($socket, $timeout);
            fwrite($socket, "exit\r\n");
        } finally {
            fclose($socket);
        }

        return $this->stripTelnetNegotiation($output);
    }

    /**
     * @param list<string> $commands
     */
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
            $output = $this->readUntil($socket, ['login:', 'username:', 'user name:', 'user:', 'name:'], $timeout);
            fwrite($socket, $username . "\r\n");

            $output .= $this->readUntil($socket, ['password:'], $timeout);
            fwrite($socket, $password . "\r\n");

            $output .= $this->readUntilPrompt($socket, $timeout);

            foreach ($commands as $command) {
                $command = trim($command);

                if ($command === '') {
                    continue;
                }

                fwrite($socket, $command . "\r\n");
                $output .= $this->readUntilPrompt($socket, $timeout);
            }

            fwrite($socket, "exit\r\n");
        } finally {
            fclose($socket);
        }

        return $this->stripTelnetNegotiation($output);
    }

    /**
     * @param resource $socket
     * @param list<string> $needles
     */
    private function readUntil($socket, array $needles, int $timeout): string
    {
        $buffer = '';
        $deadline = time() + $timeout;

        while (time() <= $deadline && !feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                $lower = strtolower($this->stripTelnetNegotiation($buffer));

                foreach ($needles as $needle) {
                    if (str_contains($lower, strtolower($needle))) {
                        return $buffer;
                    }
                }
            }

            usleep(100000);
        }

        return $buffer;
    }

    /** @param resource $socket */
    private function readUntilPrompt($socket, int $timeout): string
    {
        $buffer = '';
        $deadline = time() + $timeout;

        while (time() <= $deadline && !feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                $plain = trim($this->stripTelnetNegotiation($buffer));

                if (preg_match('/(?:[#>$]\s*)$/', $plain) === 1 || str_contains($plain, '--More--')) {
                    return $buffer;
                }
            }

            usleep(100000);
        }

        return $buffer;
    }

    private function stripTelnetNegotiation(string $value): string
    {
        return preg_replace('/\xFF[\xFB-\xFE]./s', '', $value) ?? $value;
    }
}
