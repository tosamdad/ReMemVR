<?php
namespace App\Services;

/**
 * 개발용 가짜 음성과 WAV 도구.
 * providers_fake 가 켜져 있을 때 ElevenLabs 대신 낱말마다 부드러운 음을 내는 WAV 를 만든다.
 * 글자마다 같은 길이(기본 75ms)를 주므로 글자별 정렬 정보가 오디오와 정확히 맞는다.
 * WAV 이어 붙이기, 길이 계산, PCM → WAV 변환도 여기서 한다.
 */
class FakeAudio
{
    const MS_PER_CHAR = 75;
    const SAMPLE_RATE = 16000;

    /** 펜타토닉 음계(Hz). 낱말마다 하나를 골라 듣기 편한 소리를 낸다. */
    const NOTES = [261.63, 293.66, 329.63, 392.00, 440.00, 523.25, 587.33];

    /**
     * 글자 수에 비례한 가짜 낭독 음성.
     * @return array ['audio' => WAV 바이트, 'duration_ms' => int, 'alignment' => ['chars', 'starts', 'ends'](초)]
     */
    public static function speech(string $text, int $msPerChar = self::MS_PER_CHAR, int $rate = self::SAMPLE_RATE): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            $chars = [];
        }
        $n = count($chars);
        $perChar = max(1, (int) round($msPerChar * $rate / 1000));
        $starts = [];
        $ends = [];
        for ($i = 0; $i < $n; $i++) {
            $starts[] = round($i * $perChar / $rate, 4);
            $ends[] = round(($i + 1) * $perChar / $rate, 4);
        }

        // 소리 나는 글자(문자, 숫자)가 이어진 구간을 낱말로 보고 한 음으로 낸다. 공백과 문장 부호는 쉼.
        $pcm = '';
        $i = 0;
        $wordIndex = 0;
        while ($i < $n) {
            if (!self::voiced($chars[$i])) {
                $j = $i;
                while ($j < $n && !self::voiced($chars[$j])) {
                    $j++;
                }
                $pcm .= str_repeat("\0\0", ($j - $i) * $perChar);
                $i = $j;
                continue;
            }
            $j = $i;
            while ($j < $n && self::voiced($chars[$j])) {
                $j++;
            }
            $word = implode('', array_slice($chars, $i, $j - $i));
            $freq = self::NOTES[(crc32($word) + $wordIndex) % count(self::NOTES)];
            $pcm .= self::tone($freq, $j - $i, $perChar, $rate);
            $wordIndex++;
            $i = $j;
        }

        return [
            'audio' => self::wavHeader(strlen($pcm), $rate) . $pcm,
            'duration_ms' => (int) round($n * $perChar * 1000 / $rate),
            'alignment' => ['chars' => $chars, 'starts' => $starts, 'ends' => $ends],
        ];
    }

    /** 글자 수만큼 이어지는 한 음. 글자(음절)마다 살짝 부풀었다 줄어드는 소리, 앞뒤는 페이드 처리 */
    private static function tone(float $freq, int $syllables, int $perChar, int $rate): string
    {
        $total = $syllables * $perChar;
        $fade = min((int) ($rate * 0.015), (int) ($total / 2));
        $amp = 3600.0;
        $w1 = 2 * M_PI * $freq / $rate;
        $w2 = 2 * $w1;
        $samples = [];
        for ($k = 0; $k < $total; $k++) {
            $pos = ($k % $perChar) / $perChar;
            $env = 0.55 + 0.45 * sin(M_PI * $pos);
            if ($k < $fade) {
                $env *= $k / $fade;
            } elseif ($k >= $total - $fade) {
                $env *= ($total - 1 - $k) / $fade;
            }
            $samples[] = (int) round($amp * $env * (sin($w1 * $k) + 0.25 * sin($w2 * $k)) / 1.25);
        }

        return $samples ? pack('v*', ...$samples) : '';
    }

    private static function voiced(string $ch): bool
    {
        return (bool) preg_match('/[\p{L}\p{N}]/u', $ch);
    }

    /** 16bit PCM WAV 헤더 */
    public static function wavHeader(int $dataBytes, int $rate = self::SAMPLE_RATE, int $channels = 1, int $bits = 16): string
    {
        $blockAlign = (int) ($channels * $bits / 8);

        return 'RIFF' . pack('V', 36 + $dataBytes) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $rate, $rate * $blockAlign, $blockAlign, $bits)
            . 'data' . pack('V', $dataBytes);
    }

    /** 헤더 없는 PCM(ElevenLabs pcm_* 형식)을 WAV 로 감싼다. */
    public static function pcmToWav(string $pcm, int $rate, int $channels = 1, int $bits = 16): string
    {
        return self::wavHeader(strlen($pcm), $rate, $channels, $bits) . $pcm;
    }

    /**
     * WAV 를 읽어 형식과 PCM 데이터를 돌려준다. WAV 가 아니면 null
     * @return array|null ['rate', 'channels', 'bits', 'data']
     */
    public static function parseWav(string $bytes): ?array
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') {
            return null;
        }
        $pos = 12;
        $len = strlen($bytes);
        $fmt = null;
        while ($pos + 8 <= $len) {
            $id = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            $body = $pos + 8;
            if ($id === 'fmt ' && $size >= 16) {
                $f = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($bytes, $body, 16));
                $fmt = ['rate' => (int) $f['rate'], 'channels' => max(1, (int) $f['channels']), 'bits' => max(8, (int) $f['bits'])];
            } elseif ($id === 'data' && $fmt !== null) {
                // 녹음 중 끊긴 파일은 data 크기가 실제보다 클 수 있다.
                $fmt['data'] = substr($bytes, $body, min($size, $len - $body));

                return $fmt;
            }
            $pos = $body + $size + ($size % 2);
        }

        return null;
    }

    /** WAV 재생 시간(ms). WAV 가 아니면 null */
    public static function wavDurationMs(string $bytes): ?int
    {
        $w = self::parseWav($bytes);
        if ($w === null || $w['rate'] <= 0) {
            return null;
        }
        $frame = $w['channels'] * ($w['bits'] / 8);

        return (int) round(strlen($w['data']) / $frame / $w['rate'] * 1000);
    }

    /** 같은 형식의 WAV 여러 개를 하나로 잇는다. */
    public static function concatWav(array $wavs): string
    {
        $data = '';
        $fmt = null;
        foreach ($wavs as $bytes) {
            $w = self::parseWav((string) $bytes);
            if ($w === null) {
                continue;
            }
            if ($fmt === null) {
                $fmt = $w;
            }
            $data .= $w['data'];
        }
        if ($fmt === null) {
            return '';
        }

        return self::wavHeader(strlen($data), $fmt['rate'], $fmt['channels'], $fmt['bits']) . $data;
    }
}
