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

        stream_set_timeout($socket, 30);

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

        return $this->stripAnsiEscapes($this->stripTelnetNegotiation($output));
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

        stream_set_timeout($socket, 30);

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

        return $this->stripAnsiEscapes($this->stripTelnetNegotiation($output));
    }

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

    /**
     * Membaca output dari OLT sampai muncul prompt (#/>/$).
     * Menangani dua jenis pagination OLT:
     *   - "--More--"                    → kirim spasi (Space)
     *   - "--- Enter Key To Continue ---" → kirim Enter (\r\n)
     *
     * BUG FIX: Deteksi pagination hanya pada CHUNK BARU saja, bukan seluruh
     * buffer, untuk mencegah infinite loop.
     *
     * @param resource $socket
     */
    private function readUntilPrompt($socket, int $timeout): string
    {
        $buffer        = '';
        $deadline      = time() + $timeout;
        $paginateCount = 0;
        $maxPaginate   = 300; // batas aman: maksimal 300 halaman per command

        while (time() <= $deadline && !feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk === false || $chunk === '') {
                usleep(100000);
                continue;
            }

            $buffer .= $chunk;

            // Strip telnet negotiation DAN ANSI escape codes pada chunk baru
            $chunkPlain = $this->stripAnsiEscapes($this->stripTelnetNegotiation($chunk));

            // ------------------------------------------------------------------
            // Deteksi: "--- Enter Key To Continue ---" → kirim Enter
            // ------------------------------------------------------------------
            if (
                $paginateCount < $maxPaginate
                && preg_match('/---\s*Enter\s+Key\s+To\s+Continue\s*---?/i', $chunkPlain) === 1
            ) {
                fwrite($socket, "\r\n"); // Enter untuk lanjut
                $deadline = time() + $timeout;
                $paginateCount++;
                continue;
            }

            // ------------------------------------------------------------------
            // Deteksi: "--More--" → kirim spasi
            // ------------------------------------------------------------------
            if (
                $paginateCount < $maxPaginate
                && preg_match('/--[Mm]ore--|---\s*[Mm]ore\s*---/', $chunkPlain) === 1
            ) {
                fwrite($socket, ' '); // spasi untuk lanjut
                $deadline = time() + $timeout;
                $paginateCount++;
                continue;
            }

            // Cek prompt pada seluruh buffer (terstrip ANSI + telnet)
            $fullPlain = $this->stripAnsiEscapes($this->stripTelnetNegotiation($buffer));
            if (preg_match('/[#>$]\s*$/', trim($fullPlain)) === 1) {
                return $buffer;
            }
        }

        return $buffer;
    }

    private function stripTelnetNegotiation(string $value): string
    {
        return preg_replace('/\xFF[\xFB-\xFE]./s', '', $value) ?? $value;
    }

    /**
     * Strip ANSI/VT100 escape sequences dari output terminal.
     * Contoh: \x1b[K (erase to EOL), \x1b[2J (clear screen), \x1b[1;32m (warna), dll.
     */
    private function stripAnsiEscapes(string $value): string
    {
        // CSI sequences: ESC [ ... (huruf/angka/simbol)
        $value = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $value) ?? $value;
        // OSC sequences: ESC ] ... ST
        $value = preg_replace('/\x1b\][^\x07]*\x07/', '', $value) ?? $value;
        // Escape + single char lainnya
        $value = preg_replace('/\x1b[^[\]@-Z\\-_]/', '', $value) ?? $value;
        // Backspace chars (0x08) yang OLT kadang kirim
        $value = preg_replace('/[\x08]+/', '', $value) ?? $value;
        return $value;
    }
}
