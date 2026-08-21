<?php

namespace App\Services;

use InvalidArgumentException;

class AccountNumberService
{
    public const BANK_CODE = '555';

    private const CURRENCY_MARKERS = [
        'RSD' => '10',
        'EUR' => '20',
    ];

    public function generate(string $currency, ?string $suffix = null): string
    {
        $marker = self::CURRENCY_MARKERS[strtoupper($currency)] ?? null;

        if (! $marker) {
            throw new InvalidArgumentException("Unsupported account currency: {$currency}");
        }

        $suffix = preg_replace('/\D/', '', $suffix ?? '') ?? '';
        $suffix = substr($suffix, -4);
        $randomLength = 11 - strlen($suffix);
        $body = $marker.$this->randomDigits($randomLength).$suffix;
        $base = self::BANK_CODE.$body;

        return $base.$this->calculateCheckDigits($base);
    }

    public function normalize(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    public function format(string $value): string
    {
        $number = $this->normalize($value);

        if (strlen($number) !== 18) {
            return $value;
        }

        return substr($number, 0, 3).'-'.substr($number, 3, 13).'-'.substr($number, 16, 2);
    }

    public function isValid(string $value, ?string $currency = null): bool
    {
        $number = $this->normalize($value);

        if (strlen($number) !== 18 || ! ctype_digit($number) || ! str_starts_with($number, self::BANK_CODE)) {
            return false;
        }

        if ($currency !== null) {
            $expectedMarker = self::CURRENCY_MARKERS[strtoupper($currency)] ?? null;

            if (! $expectedMarker || substr($number, 3, 2) !== $expectedMarker) {
                return false;
            }
        }

        return substr($number, -2) === $this->calculateCheckDigits(substr($number, 0, 16));
    }

    public function isQrEligible(string $value, string $currency): bool
    {
        return strtoupper($currency) === 'RSD' && $this->isValid($value, 'RSD');
    }

    public function generateIban(string $accountNumber): string
    {
        $number = $this->normalize($accountNumber);

        if (! $this->isValid($number)) {
            throw new InvalidArgumentException('A valid domestic account number is required.');
        }

        $remainder = $this->mod97($number.'272800');

        return 'RS'.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$number;
    }

    public function isValidIban(string $value): bool
    {
        $iban = strtoupper(str_replace(' ', '', $value));

        if (! preg_match('/^RS\d{20}$/', $iban)) {
            return false;
        }

        return $this->mod97(substr($iban, 4).'2728'.substr($iban, 2, 2)) === 1;
    }

    private function calculateCheckDigits(string $base): string
    {
        $remainder = $this->mod97($base.'00');

        return str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);
    }

    private function mod97(string $digits): int
    {
        $remainder = 0;

        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }

    private function randomDigits(int $length): string
    {
        $digits = '';

        for ($index = 0; $index < $length; $index++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
