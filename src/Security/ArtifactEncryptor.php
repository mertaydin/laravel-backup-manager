<?php

declare(strict_types=1);

namespace MertAydin\LaravelBackupManager\Security;

use RuntimeException;
use SodiumException;

final class ArtifactEncryptor
{
    private const CHUNK_SIZE = 65536;

    public const ALGORITHM = 'xchacha20poly1305-ietf-secretstream';

    /**
     * Encrypt an artifact using an authenticated, chunked stream.
     *
     * The key must be a base64-encoded 32-byte secret. The output format is:
     * header, followed by length-prefixed authenticated ciphertext frames.
     *
     * @return array{algorithm: string, format_version: int}
     */
    public function encrypt(string $source, string $destination, string $encodedKey): array
    {
        $this->assertAvailable();
        $key = $this->decodeKey($encodedKey);
        $input = fopen($source, 'rb');
        if ($input === false) {
            sodium_memzero($key);
            throw new RuntimeException('Unable to open the artifact for encryption.');
        }

        $output = fopen($destination, 'wb');
        if ($output === false) {
            fclose($input);
            sodium_memzero($key);
            throw new RuntimeException('Unable to create the encrypted artifact.');
        }

        @chmod($destination, 0600);

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($output, $header);
            $finalWritten = false;

            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the source artifact.');
                }

                if ($chunk === '' && feof($input)) {
                    break;
                }

                $tag = feof($input)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                $this->writeFrame($output, $ciphertext);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalWritten = true;
                    break;
                }
            }

            if (! $finalWritten) {
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    '',
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                );
                $this->writeFrame($output, $ciphertext);
            }

            if (! fflush($output)) {
                throw new RuntimeException('Unable to flush the encrypted artifact to disk.');
            }
        } catch (SodiumException $exception) {
            @unlink($destination);
            throw new RuntimeException('Artifact encryption failed.', previous: $exception);
        } catch (\Throwable $exception) {
            @unlink($destination);
            throw $exception;
        } finally {
            fclose($input);
            fclose($output);
            sodium_memzero($key);
        }

        return ['algorithm' => self::ALGORITHM, 'format_version' => 1];
    }

    public function decrypt(string $source, string $destination, string $encodedKey): void
    {
        $this->assertAvailable();
        $key = $this->decodeKey($encodedKey);
        $input = fopen($source, 'rb');
        if ($input === false) {
            sodium_memzero($key);
            throw new RuntimeException('Unable to open the encrypted artifact.');
        }

        $output = fopen($destination, 'wb');
        if ($output === false) {
            fclose($input);
            sodium_memzero($key);
            throw new RuntimeException('Unable to create the decrypted artifact.');
        }

        @chmod($destination, 0600);

        try {
            $header = $this->readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finalSeen = false;

            while (! feof($input)) {
                $lengthBytes = fread($input, 4);
                if ($lengthBytes === '' && feof($input)) {
                    break;
                }
                if ($lengthBytes === false || strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('Encrypted artifact contains a truncated frame header.');
                }

                $frameLength = unpack('Nlength', $lengthBytes)['length'];
                $maximumLength = self::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
                if ($frameLength < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $frameLength > $maximumLength) {
                    throw new RuntimeException('Encrypted artifact contains an invalid frame length.');
                }

                $ciphertext = $this->readExact($input, $frameLength);
                [$plaintext, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);

                if ($tag === false) {
                    throw new RuntimeException('Encrypted artifact authentication failed.');
                }

                if ($finalSeen) {
                    throw new RuntimeException('Encrypted artifact contains data after its final frame.');
                }

                $this->writeAll($output, $plaintext);
                $finalSeen = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (! $finalSeen) {
                throw new RuntimeException('Encrypted artifact is incomplete: final authentication frame is missing.');
            }

            if (! fflush($output)) {
                throw new RuntimeException('Unable to flush the decrypted artifact to disk.');
            }
        } catch (SodiumException $exception) {
            @unlink($destination);
            throw new RuntimeException('Artifact decryption or authentication failed.', previous: $exception);
        } catch (\Throwable $exception) {
            @unlink($destination);
            throw $exception;
        } finally {
            fclose($input);
            fclose($output);
            sodium_memzero($key);
        }
    }

    private function assertAvailable(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('The PHP sodium extension is required to use backup encryption.');
        }
    }

    private function decodeKey(string $encodedKey): string
    {
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('The backup encryption key must be base64-encoded and contain exactly 32 bytes.');
        }

        return $key;
    }

    /** @param resource $stream */
    private function writeFrame($stream, string $ciphertext): void
    {
        $this->writeAll($stream, pack('N', strlen($ciphertext)).$ciphertext);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write the complete encrypted artifact.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        $contents = '';
        while (strlen($contents) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($contents));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        if (strlen($contents) !== $length) {
            throw new RuntimeException('Encrypted artifact is truncated.');
        }

        return $contents;
    }
}
