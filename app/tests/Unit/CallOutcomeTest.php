<?php

namespace Tests\Unit;

use App\Support\CallOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CallOutcomeTest extends TestCase
{
    #[DataProvider('sipOutcomes')]
    public function test_sip_codes_are_translated_to_operator_language(int $code, string $expected, string $label): void
    {
        $status = CallOutcome::fromSip($code);
        $this->assertSame($expected, $status);
        $this->assertSame($label, CallOutcome::label($status));
    }

    public static function sipOutcomes(): array
    {
        return [
            'sem resposta' => [408, 'no_answer', 'Não atendida'],
            'número inexistente' => [404, 'invalid_number', 'Número não existe'],
            'ocupado' => [486, 'busy', 'Ocupado'],
            'recusada' => [603, 'rejected', 'Recusada'],
            'indisponível' => [503, 'unavailable', 'Indisponível'],
        ];
    }
}
