<?php

declare(strict_types=1);

class RouterosAPI
{
    public bool $debug = false;
    public int $timeout = 3;
    public int $attempts = 1;
    public int $delay = 1;
    public ?string $error = null;

    private mixed $socket = null;
    private bool $lastReadTimedOut = false;

    public function connect(string $ip, string $login, string $password, int $port = 8728): bool
    {
        $this->disconnect();
        $this->error = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->socket = @fsockopen($ip, $port, $errno, $errstr, $this->timeout);

        if ($this->socket) {
            stream_set_timeout($this->socket, $this->timeout);
            stream_set_blocking($this->socket, true);

            if ($this->login($login, $password)) {
                return true;
                }

                $this->disconnect();
                return false;
            }

            $this->error = $errstr !== '' ? $errstr : 'Tidak dapat membuka koneksi socket.';

            if ($attempt < $this->attempts) {
                sleep($this->delay);
            }
        }

        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    public function comm(string $command, array $arguments = []): array
    {
        $this->write($command);

        foreach ($arguments as $key => $value) {
            if (is_int($key)) {
                $this->write((string) $value);
                continue;
            }

            if (str_starts_with((string) $key, '?')) {
                $this->write($key . '=' . $value);
                continue;
            }

            $this->write('=' . $key . '=' . $value);
        }

        $this->write('');

        return $this->read();
    }

    private function login(string $username, string $password): bool
    {
        $response = $this->comm('/login', [
            'name' => $username,
            'password' => $password,
        ]);

        foreach ($response as $item) {
            if (isset($item['!done'])) {
                return true;
            }

            if (isset($item['!trap'])) {
                $this->error = $item['message'] ?? 'Login API MikroTik gagal.';
                return false;
            }
        }

        $this->error = 'Response login API MikroTik tidak valid.';
        return false;
    }

    private function write(string $word): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket RouterOS belum terhubung.');
        }

        $length = strlen($word);
        fwrite($this->socket, $this->encodeLength($length) . $word);
    }

    private function read(): array
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket RouterOS belum terhubung.');
        }

        $response = [];
        $current = [];
        $startedAt = time();
        $maxReadSeconds = max(3, $this->timeout * 2);

        while (true) {
            if ((time() - $startedAt) > $maxReadSeconds) {
                throw new RuntimeException('Timeout membaca response RouterOS API.');
            }

            $word = $this->readWord();

            if ($this->lastReadTimedOut) {
                throw new RuntimeException('Timeout membaca data dari RouterOS API.');
            }

            if ($word === '') {
                if ($current !== []) {
                    $response[] = $current;
                    $current = [];
                }

                $last = end($response);
                if (is_array($last) && isset($last['!done'])) {
                    break;
                }

                if (feof($this->socket)) {
                    throw new RuntimeException('Koneksi RouterOS API tertutup sebelum response selesai.');
                }

                continue;
            }

            if ($word[0] === '!') {
                if ($current !== []) {
                    $response[] = $current;
                }

                $current = [$word => true];
                continue;
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                $current[$parts[0]] = $parts[1] ?? '';
            }
        }

        return $response;
    }

    private function readWord(): string
    {
        $this->lastReadTimedOut = false;
        $length = $this->decodeLength();

        if ($this->lastReadTimedOut) {
            return '';
        }

        if ($length === 0) {
            return '';
        }

        $word = '';

        while (strlen($word) < $length && !feof($this->socket)) {
            $word .= fread($this->socket, $length - strlen($word));
            $meta = stream_get_meta_data($this->socket);

            if (!empty($meta['timed_out'])) {
                $this->lastReadTimedOut = true;
                break;
            }
        }

        return $word;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        if ($length < 0x4000) {
            return chr(($length >> 8) | 0x80) . chr($length & 0xFF);
        }

        if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    private function decodeLength(): int
    {
        $char = $this->readBytes(1);

        if ($char === '' || $char === false) {
            return 0;
        }

        $length = ord($char);

        if (($length & 0x80) === 0x00) {
            return $length;
        }

        if (($length & 0xC0) === 0x80) {
            return (($length & ~0xC0) << 8) + ord($this->readBytes(1));
        }

        if (($length & 0xE0) === 0xC0) {
            return (($length & ~0xE0) << 16)
                + (ord($this->readBytes(1)) << 8)
                + ord($this->readBytes(1));
        }

        if (($length & 0xF0) === 0xE0) {
            return (($length & ~0xF0) << 24)
                + (ord($this->readBytes(1)) << 16)
                + (ord($this->readBytes(1)) << 8)
                + ord($this->readBytes(1));
        }

        return (ord($this->readBytes(1)) << 24)
            + (ord($this->readBytes(1)) << 16)
            + (ord($this->readBytes(1)) << 8)
            + ord($this->readBytes(1));
    }

    private function readBytes(int $length): string
    {
        $data = fread($this->socket, $length);
        $meta = stream_get_meta_data($this->socket);

        if (!empty($meta['timed_out'])) {
            $this->lastReadTimedOut = true;
            throw new RuntimeException('Timeout membaca data dari RouterOS API.');
        }

        if ($data === false || strlen($data) < $length) {
            throw new RuntimeException('Response RouterOS API tidak lengkap.');
        }

        return $data;
    }
}
