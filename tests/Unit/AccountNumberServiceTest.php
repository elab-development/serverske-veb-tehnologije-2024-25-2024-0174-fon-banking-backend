<?php

namespace Tests\Unit;

use App\Services\AccountNumberService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AccountNumberServiceTest extends TestCase
{
    public function test_it_generates_valid_currency_specific_fon_account_numbers(): void
    {
        $service = new AccountNumberService;

        $rsd = $service->generate('RSD', '1234');
        $eur = $service->generate('eur', '5678');

        $this->assertMatchesRegularExpression('/^55510\d{7}1234\d{2}$/', $rsd);
        $this->assertMatchesRegularExpression('/^55520\d{7}5678\d{2}$/', $eur);
        $this->assertTrue($service->isValid($rsd, 'RSD'));
        $this->assertTrue($service->isValid($eur, 'EUR'));
        $this->assertTrue($service->isQrEligible($rsd, 'RSD'));
        $this->assertFalse($service->isQrEligible($eur, 'EUR'));
    }

    public function test_it_normalizes_formats_and_rejects_invalid_numbers(): void
    {
        $service = new AccountNumberService;
        $number = $service->generate('RSD', '1234');
        $formatted = substr($number, 0, 3).'-'.substr($number, 3, 13).'-'.substr($number, 16, 2);
        $invalidCheckDigits = substr($number, 0, 16).(substr($number, -2) === '00' ? '01' : '00');

        $this->assertSame($number, $service->normalize($formatted));
        $this->assertSame($formatted, $service->format($number));
        $this->assertTrue($service->isValid($formatted, 'RSD'));
        $this->assertFalse($service->isValid($number, 'EUR'));
        $this->assertFalse($service->isValid($invalidCheckDigits, 'RSD'));
        $this->assertFalse($service->isValid('160123456789012345', 'RSD'));
    }

    public function test_it_rejects_unsupported_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AccountNumberService)->generate('USD');
    }

    public function test_it_generates_a_valid_serbian_iban(): void
    {
        $service = new AccountNumberService;
        $accountNumber = $service->generate('EUR');
        $iban = $service->generateIban($accountNumber);

        $this->assertMatchesRegularExpression('/^RS\d{20}$/', $iban);
        $this->assertStringEndsWith($accountNumber, $iban);
        $this->assertTrue($service->isValidIban($iban));
        $this->assertTrue($service->isValidIban(implode(' ', str_split($iban, 4))));
    }
}
