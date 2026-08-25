<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\RegisterDevice;
use App\Tests\Support\InMemoryDeviceTokenStore;
use PHPUnit\Framework\TestCase;

final class RegisterDeviceTest extends TestCase
{
    private const TOKEN = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';

    public function testAPhoneIsRemembered(): void
    {
        $devices = new InMemoryDeviceTokenStore();

        (new RegisterDevice($devices))->remember(self::TOKEN, 'android');

        self::assertSame([self::TOKEN], $devices->all());
    }

    public function testTheNewerSpellingOfTheTokenIsAcceptedToo(): void
    {
        $devices = new InMemoryDeviceTokenStore();

        (new RegisterDevice($devices))->remember('ExpoPushToken[bbbbbbbbbbbbbbbbbbbbbb]', 'ios');

        self::assertCount(1, $devices->all());
    }

    /**
     * Signing in on every start is how the app says it is still there, so this must not be an
     * error and must not leave two rows for one phone.
     */
    public function testRegisteringTwiceLeavesOnePhone(): void
    {
        $devices = new InMemoryDeviceTokenStore();
        $register = new RegisterDevice($devices);

        $register->remember(self::TOKEN, 'android');
        $register->remember(self::TOKEN, 'android');

        self::assertSame([self::TOKEN], $devices->all());
    }

    public function testSomethingThatIsNotAnExpoTokenIsRefusedWhileSomebodyIsWatching(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That is not an Expo push token.');

        (new RegisterDevice(new InMemoryDeviceTokenStore()))->remember('fcm-raw-token', 'android');
    }

    public function testAnUnknownPlatformIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Platform must be one of: android, ios.');

        (new RegisterDevice(new InMemoryDeviceTokenStore()))->remember(self::TOKEN, 'blackberry');
    }

    public function testSigningOutForgetsThePhone(): void
    {
        $devices = new InMemoryDeviceTokenStore([self::TOKEN]);

        (new RegisterDevice($devices))->forget(self::TOKEN);

        self::assertSame([], $devices->all());
    }

    public function testForgettingAPhoneNobodyKnowsIsAlreadyTheAnswerAskedFor(): void
    {
        $devices = new InMemoryDeviceTokenStore([self::TOKEN]);

        (new RegisterDevice($devices))->forget('ExponentPushToken[cccccccccccccccccccccc]');

        self::assertSame([self::TOKEN], $devices->all());
    }
}
