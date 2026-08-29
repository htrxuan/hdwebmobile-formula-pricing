<?php

namespace htrxuan\hdfp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Evaluates a merchant-defined pricing formula against customer-submitted numbers.
 *
 * Motivated by CVE-2026-4001: a competing "Custom Product Addons Pro" plugin passed
 * merchant-defined formula strings straight into PHP's eval() at price-calculation
 * time -- an unauthenticated remote code execution vulnerability, since the formula
 * text (and the values substituted into it) were never restricted to arithmetic at
 * all. This class closes that vulnerability class by construction, not by trying to
 * sanitize a string before eval()-ing it (a losing game -- there is no reliable
 * denylist of "dangerous PHP" once eval() is on the table):
 *
 * - The formula is tokenized character-by-character into a strict set of token types:
 *   numbers, a fixed allow-list of variable names (the merchant's own configured
 *   field keys -- nothing else), and the four arithmetic operators plus parentheses.
 *   Any other character (a letter sequence that isn't an allow-listed variable, a
 *   function-call-looking pattern, a semicolon, a dollar sign, anything) causes the
 *   whole formula to be rejected outright.
 * - The token stream is then evaluated by a small recursive-descent arithmetic
 *   parser written by hand -- there is no interpreter, no reflection, no dynamic
 *   function dispatch of any kind. The only operations that can ever execute are
 *   addition, subtraction, multiplication, and division of PHP floats.
 * - Variable substitution is a plain array lookup against caller-supplied numeric
 *   values -- never string concatenation into a formula that then gets re-parsed.
 */
final class HDFP_Formula_Evaluator
{

    const MAX_FORMULA_LENGTH = 300;

    /**
     * Validates that a formula only uses allowed variable names and is syntactically
     * well-formed, without needing real variable values. Used when a merchant saves a
     * formula in the admin so we can reject bad input immediately, before it's ever
     * stored or evaluated against a real customer submission.
     *
     * @return true|\WP_Error
     */
    public static function validate_formula($formula, array $allowed_variables)
    {
        $dummy_values = array_fill_keys($allowed_variables, 1.0);
        $result = self::evaluate($formula, $dummy_values, $allowed_variables);
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }

    /**
     * @param string $formula
     * @param array  $variables Map of variable name => numeric value, already validated
     *                          as numeric by the caller.
     * @param array  $allowed_variables The exact set of variable names this formula is
     *                          allowed to reference (the product's own configured field
     *                          keys) -- anything else in the formula is rejected.
     * @return float|\WP_Error
     */
    public static function evaluate($formula, array $variables, array $allowed_variables)
    {
        if (!is_string($formula) || '' === trim($formula)) {
            return new \WP_Error('hdfp_empty_formula', __('Formula cannot be empty.', 'hdwebmobile-formula-pricing'));
        }

        if (strlen($formula) > self::MAX_FORMULA_LENGTH) {
            return new \WP_Error('hdfp_formula_too_long', __('Formula is too long.', 'hdwebmobile-formula-pricing'));
        }

        $tokens = self::tokenize($formula, $allowed_variables);
        if (is_wp_error($tokens)) {
            return $tokens;
        }

        $pos = 0;
        $result = self::parse_expression($tokens, $pos, $variables);
        if (is_wp_error($result)) {
            return $result;
        }

        if ($pos !== count($tokens)) {
            return new \WP_Error('hdfp_unexpected_token', __('Unexpected token in formula.', 'hdwebmobile-formula-pricing'));
        }

        if (!is_finite($result)) {
            return new \WP_Error('hdfp_non_finite_result', __('Formula produced an invalid number (division by zero or overflow).', 'hdwebmobile-formula-pricing'));
        }

        return (float) $result;
    }

    /**
     * Strict tokenizer: every character in the input must be whitespace, part of a
     * number, part of an allow-listed variable name, or one of + - * / ( ). Anything
     * else -- including a letter sequence that isn't an exact allow-listed variable --
     * is rejected as a whole, not stripped/skipped.
     *
     * @return array|\WP_Error
     */
    private static function tokenize($formula, array $allowed_variables)
    {
        $tokens = array();
        $length = strlen($formula);
        $i = 0;

        while ($i < $length) {
            $ch = $formula[$i];

            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            if (in_array($ch, array('+', '-', '*', '/', '(', ')'), true)) {
                $tokens[] = array('type' => 'op', 'value' => $ch);
                $i++;
                continue;
            }

            if (ctype_digit($ch) || '.' === $ch) {
                $start = $i;
                $seen_dot = false;
                while ($i < $length && (ctype_digit($formula[$i]) || ('.' === $formula[$i] && !$seen_dot))) {
                    if ('.' === $formula[$i]) {
                        $seen_dot = true;
                    }
                    $i++;
                }
                $number_text = substr($formula, $start, $i - $start);
                if ('.' === $number_text || '' === $number_text) {
                    return new \WP_Error('hdfp_bad_number', __('Invalid number in formula.', 'hdwebmobile-formula-pricing'));
                }
                $tokens[] = array('type' => 'number', 'value' => (float) $number_text);
                continue;
            }

            if (ctype_alpha($ch) || '_' === $ch) {
                $start = $i;
                while ($i < $length && (ctype_alnum($formula[$i]) || '_' === $formula[$i])) {
                    $i++;
                }
                $name = substr($formula, $start, $i - $start);
                if (!in_array($name, $allowed_variables, true)) {
                    return new \WP_Error(
                        'hdfp_unknown_variable',
                        sprintf(
                            /* translators: %s: the unrecognized variable name found in the formula */
                            __('Unknown variable "%s" in formula. Only configured field names are allowed.', 'hdwebmobile-formula-pricing'),
                            $name
                        )
                    );
                }
                $tokens[] = array('type' => 'variable', 'value' => $name);
                continue;
            }

            return new \WP_Error(
                'hdfp_invalid_character',
                sprintf(
                    /* translators: %s: the disallowed character found in the formula */
                    __('Character "%s" is not allowed in a formula.', 'hdwebmobile-formula-pricing'),
                    $ch
                )
            );
        }

        return $tokens;
    }

    // expression := term (('+' | '-') term)*
    private static function parse_expression(array $tokens, &$pos, array $variables)
    {
        $value = self::parse_term($tokens, $pos, $variables);
        if (is_wp_error($value)) {
            return $value;
        }

        while ($pos < count($tokens) && 'op' === $tokens[$pos]['type'] && in_array($tokens[$pos]['value'], array('+', '-'), true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $rhs = self::parse_term($tokens, $pos, $variables);
            if (is_wp_error($rhs)) {
                return $rhs;
            }
            $value = ('+' === $op) ? ($value + $rhs) : ($value - $rhs);
        }

        return $value;
    }

    // term := factor (('*' | '/') factor)*
    private static function parse_term(array $tokens, &$pos, array $variables)
    {
        $value = self::parse_factor($tokens, $pos, $variables);
        if (is_wp_error($value)) {
            return $value;
        }

        while ($pos < count($tokens) && 'op' === $tokens[$pos]['type'] && in_array($tokens[$pos]['value'], array('*', '/'), true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $rhs = self::parse_factor($tokens, $pos, $variables);
            if (is_wp_error($rhs)) {
                return $rhs;
            }
            if ('/' === $op) {
                if (0.0 === (float) $rhs) {
                    return new \WP_Error('hdfp_division_by_zero', __('Formula divides by zero with the given values.', 'hdwebmobile-formula-pricing'));
                }
                $value = $value / $rhs;
            } else {
                $value = $value * $rhs;
            }
        }

        return $value;
    }

    // factor := NUMBER | VARIABLE | '(' expression ')' | ('+' | '-') factor
    private static function parse_factor(array $tokens, &$pos, array $variables)
    {
        if ($pos >= count($tokens)) {
            return new \WP_Error('hdfp_unexpected_end', __('Formula ended unexpectedly.', 'hdwebmobile-formula-pricing'));
        }

        $token = $tokens[$pos];

        if ('op' === $token['type'] && in_array($token['value'], array('+', '-'), true)) {
            $pos++;
            $inner = self::parse_factor($tokens, $pos, $variables);
            if (is_wp_error($inner)) {
                return $inner;
            }
            return ('-' === $token['value']) ? -$inner : $inner;
        }

        if ('number' === $token['type']) {
            $pos++;
            return $token['value'];
        }

        if ('variable' === $token['type']) {
            $pos++;
            return isset($variables[$token['value']]) ? (float) $variables[$token['value']] : 0.0;
        }

        if ('op' === $token['type'] && '(' === $token['value']) {
            $pos++;
            $inner = self::parse_expression($tokens, $pos, $variables);
            if (is_wp_error($inner)) {
                return $inner;
            }
            if ($pos >= count($tokens) || 'op' !== $tokens[$pos]['type'] || ')' !== $tokens[$pos]['value']) {
                return new \WP_Error('hdfp_missing_paren', __('Missing closing parenthesis in formula.', 'hdwebmobile-formula-pricing'));
            }
            $pos++;
            return $inner;
        }

        return new \WP_Error('hdfp_unexpected_token', __('Unexpected token in formula.', 'hdwebmobile-formula-pricing'));
    }
}
