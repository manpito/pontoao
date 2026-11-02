<?php

declare(strict_types=1);

use App\Services\LimitesHorasExtra;
use PHPUnit\Framework\TestCase;

final class LimitesHorasExtraTest extends TestCase
{
    public function testDentroDosLimites(): void
    {
        $this->assertNull(LimitesHorasExtra::verificar('c', 120, true, 0, 0));
    }

    public function testExcedeDuasHorasEmDiaNormal(): void
    {
        $this->assertNotNull(LimitesHorasExtra::verificar('c', 121, true, 0, 0));
    }

    public function testDiaDeDescansoNaoTemLimiteDiarioMasContaNoMes(): void
    {
        $this->assertNull(LimitesHorasExtra::verificar('c', 480, false, 0, 0));
        $this->assertNotNull(LimitesHorasExtra::verificar('c', 480, false, 2000, 2000));
    }

    public function testExcedeAno(): void
    {
        $this->assertNotNull(LimitesHorasExtra::verificar('b', 60, true, 0, 11950));
    }

    public function testFundamentosADSemLimites(): void
    {
        $this->assertNull(LimitesHorasExtra::verificar('a', 600, true, 2400, 12000));
        $this->assertNull(LimitesHorasExtra::verificar('d', 600, true, 2400, 12000));
    }

    public function testMinutosEntre(): void
    {
        $this->assertSame(120, LimitesHorasExtra::minutosEntre('17:00', '19:00'));
        $this->assertSame(600, LimitesHorasExtra::minutosEntre('20:00', '06:00'));
    }
}
