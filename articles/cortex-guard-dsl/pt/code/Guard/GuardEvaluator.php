<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

use App\Models\V2\Agent\AgentExecution;
use App\Services\V2\Agent\Guard\CompiledGuard;
use App\Services\V2\Agent\Guard\GuardContext;
use App\Services\V2\Agent\Guard\GuardNodeType;
use App\Services\V2\Agent\Guard\GuardRegistry;
use App\Services\V2\Agent\Guard\RuntimeSignals;
use App\Services\V2\Agent\Kernel\AgentContext;
use App\Services\V2\Agent\Kernel\TransitionDefinition;

/**
 * Avalia as guardas compiladas declaradas numa {@see TransitionDefinition}.
 *
 * Percorre cada {@see CompiledGuard} recursivamente e delega ao
 * {@see GuardRegistry} quando encontra nós folha (signal ou threshold).
 *
 * Divisão de responsabilidades:
 *   - {@see GuardParser}    → texto → AST (boot-time)
 *   - {@see CompiledGuard}  → estrutura da expressão (imutável, sem dependências)
 *   - {@see GuardEvaluator} → travessia da AST (runtime)
 *   - {@see GuardRegistry}  → avaliação de átomos (runtime)
 *
 * O GuardEvaluator nunca chama o GuardParser — opera exclusivamente sobre
 * ASTs já compiladas. {@see \App\Services\V2\Agent\Exceptions\InvalidGuardExpressionException}
 * não pode ocorrer aqui; se ocorrer, é re-lançada como {@see \LogicException}
 * porque indica um bug interno, não input inválido.
 *
 * O resultado é um value object anónimo compatível com o contrato do
 * {@see ExecutionRuntime}: object{ passed: bool, failedGuard: string }
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class GuardEvaluator
{
    public function __construct(
        private readonly GuardRegistry $registry,
    ) {}

    /**
     * Avalia todas as guardas compiladas da transição em sequência.
     *
     * A primeira a falhar determina o `failedGuard` devolvido.
     * Transições sem guardas transitam sempre — este método não é chamado.
     *
     * @param  list<CompiledGuard> $guards    ASTs compiladas em boot-time.
     * @param  AgentContext        $context   Contexto com delta já aplicado.
     * @param  AgentExecution      $execution Execução em transição.
     * @param  RuntimeSignals      $signals   Flags transitórias da iteração corrente.
     * @return object{passed: bool, failedGuard: string}
     */
    public function evaluate(
        array          $guards,
        AgentContext   $context,
        AgentExecution $execution,
        RuntimeSignals $signals,
    ): object {
        $ctx = new GuardContext(
            context:   $context,
            execution: $execution,
            metrics:   $context->executionMetrics,
            signals:   $signals,
        );

        foreach ($guards as $guard) {
            if (! $this->evaluateNode($guard, $ctx)) {
                if ($guard->sourceExpression === null) {
                    throw new \LogicException(
                        "Guarda de runtime sem sourceExpression — indica bug de compilação: "
                        . "a guarda não foi produzida por GuardParser::compile()."
                    );
                }

                $label = $guard->sourceExpression;

                return new readonly class(false, $label) {
                    public function __construct(
                        public bool   $passed,
                        public string $failedGuard,
                    ) {}
                };
            }
        }

        return new readonly class(true, '') {
            public function __construct(
                public bool   $passed,
                public string $failedGuard,
            ) {}
        };
    }

    // ─── Travessia da AST ─────────────────────────────────────────────────────

    /**
     * Avalia um nó da AST recursivamente.
     *
     * Nós compostos (and, or, not) resolvem os filhos antes de avaliar.
     * Nós folha (signal, threshold) delegam ao {@see GuardRegistry}.
     *
     * @throws \LogicException  Se o tipo do nó for desconhecido (bug interno).
     */
    private function evaluateNode(CompiledGuard $node, GuardContext $ctx): bool
    {
        return match ($node->type) {
            GuardNodeType::Signal    => $this->registry->evaluate($node->identifier, $ctx),
            GuardNodeType::Threshold => $this->registry->evaluate(
                "{$node->identifier} >= {$node->threshold}",
                $ctx,
            ),
            GuardNodeType::Not       => ! $this->evaluateNode($node->left, $ctx),
            GuardNodeType::And       => $this->evaluateNode($node->left, $ctx)
                                     && $this->evaluateNode($node->right, $ctx),
            GuardNodeType::Or        => $this->evaluateNode($node->left, $ctx)
                                     || $this->evaluateNode($node->right, $ctx),
            default                  => throw new \LogicException(
                "Tipo de nó desconhecido na AST: [{$node->type->value}]. Isto é um bug interno."
            ),
        };
    }
}
