<?php

declare(strict_types=1);

namespace App\Services\V2\Agent\Guard;

use App\Services\V2\Agent\Exceptions\InvalidGuardExpressionException;

/**
 * Compila expressões de guarda para {@see CompiledGuard} em boot-time.
 *
 * Gramática suportada (EBNF simplificado):
 *   expression  ::= or_expr
 *   or_expr     ::= and_expr ( "OR" and_expr )*
 *   and_expr    ::= not_expr ( "AND" not_expr )*
 *   not_expr    ::= "NOT" not_expr | "(" expression ")" | atom
 *   atom        ::= identifier ">=" integer | identifier
 *
 * Operadores em maiúsculas. Precedência: NOT > AND > OR.
 * Parênteses sobrepõem precedência.
 *
 * Contrato definitivo:
 *   - {@see self::compile()} — produz {@see CompiledGuard} para uso em runtime.
 *   - {@see self::validate()} — wrapper de boot-time chamado pelo {@see TransitionMap};
 *     compila e descarta a AST. Serve apenas para detectar expressões inválidas no arranque.
 *
 * {@see InvalidGuardExpressionException} é exclusivamente de boot-time.
 * Runtime opera sobre {@see CompiledGuard} — sem reparse, sem retokenização.
 * O {@see GuardEvaluator} é o único caller de runtime; nunca chama este parser.
 *
 * @package App\Services\V2\Agent\Guard
 * @author  Eduardo Costa Nkuansambu
 */
final class GuardParser
{
    public function __construct(
        private readonly GuardRegistry $registry,
    ) {}

    // ─── API pública ──────────────────────────────────────────────────────────

    /**
     * Compila uma expressão de guarda para AST em boot-time.
     *
     * Valida sintaxe e existência de cada identificador no registry.
     * A {@see CompiledGuard} devolvida é estrutura pura — sem dependências
     * de avaliação. Avaliação em runtime é responsabilidade do {@see GuardEvaluator}.
     *
     * @throws InvalidGuardExpressionException  Se a expressão for sintaticamente inválida
     *                                          ou referenciar um identificador desconhecido.
     */
    public function compile(string $expression): CompiledGuard
    {
        $tokens   = $this->tokenize($expression);
        $position = 0;
        $node     = $this->parseOrExpr($tokens, $position);

        if ($position !== count($tokens)) {
            throw new InvalidGuardExpressionException(
                "Expressão de guarda com tokens inesperados a partir da posição {$position}: [{$expression}]"
            );
        }

        return $node->withSourceExpression($expression);
    }

    /**
     * Valida uma expressão de guarda em boot-time sem reter a AST.
     *
     * Chamado pelo {@see \App\Services\V2\Agent\Kernel\TransitionMap} no construtor.
     * Uma excepção aqui impede o arranque da aplicação com um mapa inválido.
     *
     * @throws InvalidGuardExpressionException  Se a expressão for inválida.
     */
    public function validate(string $expression): void
    {
        $this->compile($expression);
    }

    // ─── Tokenização ─────────────────────────────────────────────────────────

    /**
     * @return list<string>
     * @throws InvalidGuardExpressionException
     */
    private function tokenize(string $expression): array
    {
        $pattern = '/(\bAND\b|\bOR\b|\bNOT\b|>=|\(|\)|\d+|[a-z_][a-z0-9_.]*)/';

        preg_match_all($pattern, $expression, $matches);
        $tokens = $matches[0];

        if (empty($tokens)) {
            throw new InvalidGuardExpressionException(
                "Expressão de guarda vazia ou sem tokens reconhecíveis: [{$expression}]"
            );
        }

        $normalized = preg_replace('/\s+/', '', $expression);
        $rebuilt    = implode('', $tokens);

        if ($normalized !== $rebuilt) {
            throw new InvalidGuardExpressionException(
                "Expressão de guarda contém caracteres não reconhecidos: [{$expression}]"
            );
        }

        return $tokens;
    }

    // ─── Parser recursivo descendente ────────────────────────────────────────

    /** @param list<string> $tokens */
    private function parseOrExpr(array $tokens, int &$pos): CompiledGuard
    {
        $node = $this->parseAndExpr($tokens, $pos);

        while ($pos < count($tokens) && $tokens[$pos] === 'OR') {
            $pos++;
            $right = $this->parseAndExpr($tokens, $pos);
            $node  = CompiledGuard::or($node, $right);
        }

        return $node;
    }

    /** @param list<string> $tokens */
    private function parseAndExpr(array $tokens, int &$pos): CompiledGuard
    {
        $node = $this->parseNotExpr($tokens, $pos);

        while ($pos < count($tokens) && $tokens[$pos] === 'AND') {
            $pos++;
            $right = $this->parseNotExpr($tokens, $pos);
            $node  = CompiledGuard::and($node, $right);
        }

        return $node;
    }

    /** @param list<string> $tokens */
    private function parseNotExpr(array $tokens, int &$pos): CompiledGuard
    {
        if ($pos < count($tokens) && $tokens[$pos] === 'NOT') {
            $pos++;
            $inner = $this->parseNotExpr($tokens, $pos);
            return CompiledGuard::not($inner);
        }

        if ($pos < count($tokens) && $tokens[$pos] === '(') {
            $pos++;
            $node = $this->parseOrExpr($tokens, $pos);

            if ($pos >= count($tokens) || $tokens[$pos] !== ')') {
                throw new InvalidGuardExpressionException(
                    "Parêntese de fecho em falta na expressão de guarda."
                );
            }

            $pos++;
            return $node;
        }

        return $this->parseAtom($tokens, $pos);
    }

    /** @param list<string> $tokens */
    private function parseAtom(array $tokens, int &$pos): CompiledGuard
    {
        if ($pos >= count($tokens)) {
            throw new InvalidGuardExpressionException(
                "Expressão de guarda incompleta — esperado átomo."
            );
        }

        $identifier = $tokens[$pos];
        $pos++;

        if ($pos < count($tokens) && $tokens[$pos] === '>=') {
            $pos++;

            if ($pos >= count($tokens) || ! ctype_digit($tokens[$pos])) {
                throw new InvalidGuardExpressionException(
                    "Esperado inteiro após '>=' na guarda [{$identifier}]."
                );
            }

            $threshold = (int) $tokens[$pos];
            $pos++;

            // Validação explícita aqui, e não apenas no assert() do CompiledGuard.
            // O assert() pode estar desactivado em produção (zend.assertions = -1).
            // O parser tem o contexto necessário para uma excepção descritiva e é
            // o único ponto de entrada que produz nós Threshold — a responsabilidade
            // de rejeitar valores inválidos pertence-lhe.
            // A verificação precede a consulta ao registry: um limiar de zero é um
            // erro sintáctico da expressão — não há razão para validar o identificador
            // quando o operando já é inválido.
            if ($threshold <= 0) {
                throw new InvalidGuardExpressionException(
                    "O valor do threshold para [{$identifier}] deve ser positivo; [{$threshold}] fornecido."
                );
            }

            if (! $this->registry->existsThreshold($identifier)) {
                throw new InvalidGuardExpressionException(
                    "Identificador de threshold desconhecido: [{$identifier}]"
                );
            }

            return CompiledGuard::threshold($identifier, $threshold);
        }

        if (! $this->registry->existsSignal($identifier)) {
            throw new InvalidGuardExpressionException(
                "Guarda desconhecida: [{$identifier}]"
            );
        }

        return CompiledGuard::signal($identifier);
    }
}