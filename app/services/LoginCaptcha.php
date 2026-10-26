<?php

/** Local, session-bound image challenge. Answers never leave the server. */
class LoginCaptcha
{
    public const TTL = 120;
    private $clock;
    private $generator;
    private const GLYPHS = [
        '2'=>'0E11010204081F','3'=>'1E01010E01011E','4'=>'02060A121F0202','6'=>'0608101E11110E',
        '7'=>'1F010204080808','8'=>'0E11110E11110E','9'=>'0E11110F01020C',
        'A'=>'0E11111F111111','C'=>'0F10101010100F','D'=>'1E11111111111E','E'=>'1F10101E10101F',
        'F'=>'1F10101E101010','G'=>'0E11101711110F','H'=>'1111111F111111','J'=>'0702020212120C',
        'K'=>'11121418141211','L'=>'1010101010101F','M'=>'111B1515111111','N'=>'11191513111111',
        'P'=>'1E11111E101010','Q'=>'0E11111115120D','R'=>'1E11111E141211','T'=>'1F040404040404',
        'U'=>'1111111111110E','V'=>'11111111110A04','W'=>'11111115151B11','X'=>'11110A040A1111',
        'Y'=>'11110A04040404',
    ];

    public function __construct(?callable $clock = null, ?callable $generator = null)
    {
        $this->clock = $clock ?? static function () { return time(); };
        $this->generator = $generator;
    }

    public function issue(): array
    {
        $alphabet = array_keys(self::GLYPHS);
        $answer = '';
        if ($this->generator) { $answer = ($this->generator)(); }
        else { for ($i = 0; $i < 5; $i++) { $answer .= $alphabet[random_int(0, count($alphabet) - 1)]; } }
        if (strlen($answer) !== 5 || strspn($answer, implode('', $alphabet)) !== 5) { throw new LogicException('Invalid challenge generator'); }
        $id = bin2hex(random_bytes(24));
        $expires = ($this->clock)() + self::TTL;
        $_SESSION['login_captcha'] = ['id' => $id, 'hash' => hash('sha256', $id . ':' . $answer), 'expires_at' => $expires];
        return ['captcha_id' => $id, 'image' => 'data:image/svg+xml;base64,' . base64_encode($this->render($answer)),
            'expires_in' => self::TTL, 'expires_at' => $expires];
    }

    public function consume($id, $answer): bool
    {
        $challenge = $_SESSION['login_captcha'] ?? [];
        unset($_SESSION['login_captcha']);
        if (!is_string($id) || !is_string($answer) || strlen($id) !== 48 || strlen($answer) > 12) { return false; }
        $answer = strtoupper(trim($answer));
        return strlen($answer) === 5 && ($challenge['expires_at'] ?? 0) > ($this->clock)()
            && hash_equals((string) ($challenge['id'] ?? ''), $id)
            && hash_equals((string) ($challenge['hash'] ?? ''), hash('sha256', $id . ':' . $answer));
    }

    private function render(string $answer): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="54" viewBox="0 0 180 54"><rect width="180" height="54" rx="6" fill="#f0f4ea"/>';
        foreach (str_split($answer) as $i => $letter) {
            $path = '';
            foreach (str_split(self::GLYPHS[$letter], 2) as $y => $row) {
                for ($x = 0; $x < 5; $x++) {
                    if (hexdec($row) & (1 << (4 - $x))) { $path .= 'M' . ($x * 4) . ',' . ($y * 4) . 'h4v4h-4z'; }
                }
            }
            $svg .= '<path fill="#' . ['31482e','405c37','294d48'][random_int(0, 2)] . '" transform="translate('
                . (12 + $i * 33 + random_int(-2, 2)) . ' ' . random_int(10, 15) . ') rotate(' . random_int(-12, 12) . ' 10 14)" d="' . $path . '"/>';
        }
        for ($i = 0; $i < 5; $i++) {
            $svg .= '<path d="M0 ' . random_int(4, 50) . ' Q90 ' . random_int(0, 54) . ' 180 ' . random_int(4, 50) . '" stroke="#839a75" stroke-width="1" opacity=".5" fill="none"/>';
        }
        for ($i = 0; $i < 45; $i++) { $svg .= '<circle cx="' . random_int(1, 179) . '" cy="' . random_int(1, 53) . '" r=".7" fill="#81916f"/>'; }
        return $svg . '</svg>';
    }
}
