<?php

declare(strict_types=1);

namespace WapplerSystems\FormBrevo\Tests\Unit\Finishers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\FormBrevo\Brevo\ApiException;
use WapplerSystems\FormBrevo\Finishers\BrevoSubscribeFinisher;

final class BrevoSubscribeFinisherTest extends TestCase
{
    public static function listIdProvider(): array
    {
        return [
            'comma-separated string' => ['12, 7,12', [12, 7]],
            'yaml list' => [[3, '4', 'x'], [3, 4]],
            'empty' => ['', []],
            'single int' => [5, [5]],
        ];
    }

    #[Test]
    #[DataProvider('listIdProvider')]
    public function normalizeListIdsKeepsPositiveUniqueIds(mixed $value, array $expected): void
    {
        self::assertSame($expected, BrevoSubscribeFinisher::normalizeListIds($value));
    }

    #[Test]
    public function normalizeAttributesDropsEmptyJoinsArraysAndSendsCheckedCheckboxesAsTrue(): void
    {
        $result = BrevoSubscribeFinisher::normalizeAttributes(
            [
                'vorname' => ' Anna ',
                'NACHNAME' => '',
                'WEBSITE' => null,
                'INTERESTS' => ['BIM', '', 'CAD'],
                'OPT_IN' => '1',
                'NEWSLETTER' => '',
                'SOURCE' => 'cpid-002',
                'LANGUAGE' => 2,
            ],
            ['OPT_IN', 'NEWSLETTER'],
        );

        self::assertSame([
            'VORNAME' => 'Anna',
            'INTERESTS' => 'BIM, CAD',
            'OPT_IN' => true,
            'SOURCE' => 'cpid-002',
            'LANGUAGE' => 2,
        ], $result);
    }

    public static function rejectionProvider(): array
    {
        return [
            'invalid email' => [400, '{"code":"invalid_parameter","message":"Invalid email address"}', true],
            'invalid list' => [400, '{"code":"invalid_parameter","message":"listIds are invalid"}', false],
            'bad key' => [401, '{"code":"unauthorized","message":"Key not found"}', false],
            'duplicate' => [400, '{"code":"duplicate_parameter","message":"Contact already exist"}', false],
            'no answer' => [0, '', false],
        ];
    }

    #[Test]
    #[DataProvider('rejectionProvider')]
    public function onlyAnInvalidAddressCountsAsVisitorError(int $status, string $body, bool $expected): void
    {
        self::assertSame($expected, (new ApiException('x', $status, $body, 1))->isRejectedEmail());
    }
}
