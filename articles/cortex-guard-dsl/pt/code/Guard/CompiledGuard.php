<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

/**
 * AST imutável de uma expressão de guarda, produzida pelo {@see GuardParser} em boot-time.
 *
 * Representa exclusivamente estrutura — sem dependências de avaliação.
 * Cada nó tem um {@see GuardNodeType} que delimita quais propriedades são relevantes:
 *
 *   - Signal    → $identifier preenchido; $threshold, $left, $right = null
 *   - Threshold → $identifier e $threshold preenchidos; $left, $right = null
 *   - Not       → $left preenchido (filho único); $identifier, $threshold, $right = null
 *   - And / Or  → $left e $right preenchidos; $identifier, $threshold = null
 *
 * Apenas o nó raiz da AST tem {@see self::$sourceExpression} preenchida —
 * a expressão original tal como declarada no {@see \App\Services\V2\Agent\Kernel\TransitionMap}.
 * Nós internos têm `sourceExpression = null`.
 *
 * A expressão original serve exclusivamente para diagnóstico: mensagens de excepção,
 * logs e auditoria. Nunca é re-parseada em runtime.
 *
 * A avaliação é responsabilidade do {@see GuardEvaluator}, que percorre a árvore
 * e delega ao {@see GuardRegistry} quando encontra nós folha (Signal ou Threshold).
 * O CompiledGuard não conhece nem o registry nem o contexto de execução.
 *
 * Todos os nós são criados pelos factory estáticos — o construtor privado
 * impede instâncias ad hoc fora do {@see GuardParser}.
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class CompiledGuard
{
    /**
     * @param GuardNodeType      $type             Tipo do nó.
     * @param string|null        $identifier       Identificador do átomo (Signal ou Threshold).
     * @param int|null           $threshold        Valor de comparação (apenas nós Threshold).
     * @param CompiledGuard|null $left             Filho esquerdo (And, Or) ou único filho (Not).
     * @param CompiledGuard|null $right            Filho direito (And, Or).
     * @param string|null        $sourceExpression Expressão original (apenas no nó raiz; null nos nós internos).
     */
    private function __construct(
        public readonly GuardNodeType  $type,
        public readonly ?string        $identifier,
        public readonly ?int           $threshold,
        public readonly ?CompiledGuard $left,
        public readonly ?CompiledGuard $right,
        public readonly ?string        $sourceExpression,
    ) {}

    // ─── Factory estáticos (nós internos — sem sourceExpression) ─────────────

    public static function signal(string $identifier): self
    {
        // Um nó Signal é um átomo puro: só o identificador, sem filhos nem limiar.
        // A string vazia é rejeitada porque um identificador sem nome não pode ser
        // resolvido pelo GuardRegistry em runtime.
        assert($identifier !== '', 'Signal identifier cannot be empty.');

        return new self(GuardNodeType::Signal, $identifier, null, null, null, null);
    }

    public static function threshold(string $identifier, int $threshold): self
    {
        // Um nó Threshold compara um contador a um limiar positivo.
        // Limiares <= 0 não fazem sentido semântico na linguagem de guardas:
        // "consecutive_failures >= 0" seria sempre verdadeiro, nunca uma guarda útil.
        assert($identifier !== '', 'Threshold identifier cannot be empty.');
        assert($threshold > 0,     'Threshold value must be positive.');

        return new self(GuardNodeType::Threshold, $identifier, $threshold, null, null, null);
    }

    public static function not(self $inner): self
    {
        // Not é uma negação unária: exactamente um filho ($left), sem $right.
        return new self(GuardNodeType::Not, null, null, $inner, null, null);
    }

    public static function and(self $left, self $right): self
    {
        // And requer os dois filhos; a avaliação é em short-circuit (para no primeiro false).
        return new self(GuardNodeType::And, null, null, $left, $right, null);
    }

    public static function or(self $left, self $right): self
    {
        // Or requer os dois filhos; a avaliação é em short-circuit (para no primeiro true).
        return new self(GuardNodeType::Or, null, null, $left, $right, null);
    }

    // ─── Factory para o nó raiz (com sourceExpression) ───────────────────────

    /**
     * Anota a expressão original no nó raiz da AST.
     *
     * Chamado pelo {@see GuardParser} após compilação completa da expressão.
     * Devolve uma cópia do nó com `sourceExpression` preenchida — o nó original
     * não é mutado.
     */
    public function withSourceExpression(string $expression): self
    {
        return new self(
            $this->type,
            $this->identifier,
            $this->threshold,
            $this->left,
            $this->right,
            $expression,
        );
    }
}