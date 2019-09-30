<?php
/**
 * This file is part of the Enlighten package
 * @license http://opensource.org/licenses/MIT
 */
declare(strict_types=1);
namespace DecodeLabs\Enlighten;

class Highlighter
{
    /**
     * Extract a specific line with $buffer lines around it
     */
    public function extract(string $source, int $line, int $buffer=8): string
    {
        $line = max(1, $line);
        $buffer = min(30, max(1, $buffer));
        $startLine = max(1, $line - $buffer);
        $endLine = $line + $buffer;

        return $this->highlight($source, $startLine, $endLine, $line);
    }

    /**
     * Extract a specific line from file with $buffer lines around it
     */
    public function extractFromFile(string $path, int $line, int $buffer=8): string
    {
        if (!file_exists($path)) {
            return '';
        }

        return $this->extract(file_get_contents($path), $line, $buffer);
    }

    /**
     * Highlight PHP source from $startLine to $endLine, focussing on $highlight
     */
    public function highlight(string $source, ?int $startLine=null, ?int $endLine=null, ?int $highlight=null): string
    {
        if ($startLine !== null) {
            $startLine = max(1, $startLine);
        }

        $tokens = token_get_all($source, \TOKEN_PARSE);
        $source = '';

        if ($endLine !== null && $startLine === null) {
            $startLine = 1;
        }

        $lastLine = 1;
        $history = [];

        while (!empty($tokens)) {
            $token = array_shift($tokens);
            array_unshift($history, $token);

            if (count($history) > 20) {
                array_pop($history);
            }

            if (is_array($token)) {
                $lastLine = $token[2];
                $name = substr(token_name($token[0]), 2);
                $name = strtolower(str_replace('_', '-', $name));

                if ($startLine !== null) {
                    if ($name === 'whitespace' || $name === 'doc-comment') {
                        if ($lastLine >= $endLine) {
                            $parts = explode("\x00", str_replace("\n", "\x00\n", $token[1]));
                        } else {
                            $parts = explode("\x00", str_replace("\n", "\n\x00", $token[1]));
                        }

                        $token[1] = array_shift($parts);

                        if (!empty($rem = implode($parts))) {
                            $new = $token;
                            $new[1] = $rem;
                            $new[2] += 1;
                            array_unshift($tokens, $new);
                        }
                    }

                    if ($startLine !== null && $lastLine < $startLine) {
                        continue;
                    }
                    if ($endLine !== null && $lastLine > $endLine) {
                        break;
                    }
                }

                $attrs = [];
                $name = $this->normalizeName($origName = $name);

                switch ($origName) {
                    case 'whitespace':
                        $source .= $token[1];
                        continue 2;

                    case 'constant-encapsed-string':
                        $quote = substr($token[1], 0, 1);
                        $token[1] = substr($token[1], 1, -1);
                        $attrs['data-quote'] = $quote;
                        break;

                    case 'variable':
                        if ($token[1] === '$this') {
                            $name .= ' this';
                        }
                        break;

                    case 'string':
                        $type = $this->getNameType($history, $tokens);

                        if ($type !== null) {
                            $name .= ' '.$type;
                        }
                        break;
                }


                $inner = explode("\n", str_replace("\r", '', $token[1]));

                foreach ($attrs as $key => $val) {
                    $attrs[$key] = ' '.$key.'="'.$this->esc($val).'"';
                }

                $attrs = implode($attrs);

                foreach ($inner as &$part) {
                    if (!empty($part)) {
                        $part = '<span class="'.$name.'"'.$attrs.'>'.$this->esc($part).'</span>';
                    }
                }

                $source .= implode("\n", $inner);
            } else {
                if ($startLine !== null && $lastLine < $startLine) {
                    continue;
                }
                if ($endLine !== null && $lastLine > $endLine) {
                    break;
                }

                $source .= '<span class="g">'.$this->esc($token).'</span>';
            }
        }

        $lines = explode("\n", $source);
        $output = [];
        $i = $startLine ?? 1;

        if ($startLine > 1) {
            $output[] = '<span class="line"><span class="number x">…</span></span>';
        } else {
            $output[] = '<span class="line spacer"><span class="number x"></span></span>';
        }

        foreach ($lines as $line) {
            $output[] = '<span class="line'.($i === $highlight ? ' highlighted' : null).'"><span class="number">'.$i.'</span>'.$line.'</span>';
            $i++;
        }

        if ($endLine !== null && $i > $endLine) {
            $output[] = '<span class="line"><span class="number x">…</span></span>';
        } else {
            $output[] = '<span class="line spacer"><span class="number x"></span></span>';
        }

        return '<samp class="source">'.implode("\n", $output).'</samp>';
    }

    /**
     * Highlight PHP source from file from $startLine to $endLine, focussing on $highlight
     */
    public function highlightFile(string $path, ?int $startLine=null, ?int $endLine=null, ?int $highlight=null): string
    {
        if (!file_exists($path)) {
            return '';
        }

        return $this->highlight(file_get_contents($path), $startLine, $endLine, $highlight);
    }

    /**
     * Attempt to parse name token type
     */
    protected function getNameType(array $history, array $tokens): ?string
    {
        $current = array_shift($history);

        switch ($current[1]) {
            case 'null':
                return 'null';

            case 'true':
            case 'false':
                return 'bool';
        }

        $maybeFunction = $maybeClassReturn = false;

        switch ($tokens[0][0]) {
            case \T_OBJECT_OPERATOR:
                return 'member';

            case \T_PAAMAYIM_NEKUDOTAYIM:
                return 'class';

            case \T_NS_SEPARATOR:
                return 'namespace';

            case \T_VARIABLE:
                return 'class';

            case \T_WHITESPACE:
                switch ($tokens[1][0]) {
                    case \T_VARIABLE:
                    case \T_ELLIPSIS:
                        return 'class';
                }

                if ($tokens[1] === '{') {
                    $maybeClassReturn = true;
                }
                break;
        }

        if ($tokens[0] === '(') {
            $maybeFunction = true;
        }

        if (preg_match('/^[A-Z_]+$/', $current[1]) && !$maybeFunction) {
            return 'constant';
        }

        while (!empty($history)) {
            $token = array_shift($history);

            if (is_array($token)) {
                if ($token[0] === \T_WHITESPACE) {
                    continue;
                }

                if ($maybeFunction) {
                    switch ($token[0]) {
                        case \T_NS_SEPARATOR:
                        case \T_STRING:
                            continue 2;

                        case \T_NEW:
                            return 'class';

                        default:
                            return 'function';
                    }
                }

                switch ($token[0]) {
                    case \T_CONST:
                        return 'constant';

                    case \T_PAAMAYIM_NEKUDOTAYIM:
                        if (!$maybeFunction) {
                            return 'constant';
                        }
                        return null;

                    case \T_OBJECT_OPERATOR:
                        if (!$maybeFunction) {
                            return 'member';
                        }
                        return null;

                    case \T_EXTENDS:
                    case \T_IMPLEMENTS:
                    case \T_CLASS:
                    case \T_USE:
                    case \T_NS_SEPARATOR:
                        return 'class';
                }

                if ($maybeClassReturn) {
                    return 'class return';
                }

                return null;
            } else {
                if ($maybeFunction) {
                    return 'function';
                } elseif ($token === ';') {
                    return null;
                }

                switch ($token) {
                    case ':':
                        if ($tokens[0] === '{' || $tokens[1] === '{') {
                            return 'class return';
                        }
                }
            }
        }

        return null;
    }

    /**
     * Escape a value for HTML
     */
    protected function esc(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }


    /**
     * Normalize name
     */
    protected function normalizeName(string $name): string
    {
        switch ($name) {
            // Keywords
            case 'abstract':
            case 'array':
            case 'as':
            case 'class':
            case 'clone':
            case 'const':
            case 'declare':
            case 'default':
            case 'echo':
            case 'enddeclare':
            case 'extends':
            case 'final':
            case 'function':
            case 'global':
            case 'implements':
            case 'include':
            case 'include-once':
            case 'instanceof':
            case 'insteadof':
            case 'interface':
            case 'namespace':
            case 'new':
            case 'print':
            case 'private':
            case 'public':
            case 'protected':
            case 'require':
            case 'require-once':
            case 'static':
            case 'trait':
            case 'use':
            case 'var':
                return 'keyword '.$name;

            // Flow control keywords
            case 'break':
            case 'case':
            case 'catch':
            case 'continue':
            case 'do':
            case 'else':
            case 'elseif':
            case 'endfor':
            case 'endforeach':
            case 'endif':
            case 'endswitch':
            case 'endwhile':
            case 'exit':
            case 'finally':
            case 'for':
            case 'foreach':
            case 'goto':
            case 'if':
            case 'return':
            case 'switch':
            case 'throw':
            case 'try':
            case 'while':
            case 'yield':
            case 'yield-from':
                return 'keyword flow '.$name;


            // Types
            case 'callable':
                return 'type '.$name;

            // Casts
            case 'array-cast':
            case 'bool-cast':
            case 'double-cast':
            case 'int-cast':
            case 'object-cast':
            case 'string-cast':
            case 'unset-cast':
                return 'cast '.$name;

            // Tags
            case 'close-tag':
            case 'open-tag':
            case 'open-tag-with-echo':
                return 'tag '.$name;

            // Operator
            case 'and-equal':
            case 'boolean-and':
            case 'boolean-or':
            case 'coalesce':
            case 'concat-equal':
            case 'dec':
            case 'div-equal':
            case 'ellipsis':
            case 'inc':
            case 'is-equal':
            case 'is-greater-or-equal':
            case 'is-identical':
            case 'is-not-equal':
            case 'is-not-identical':
            case 'is-smaller-or-equal':
            case 'spaceship':
            case 'logical-and':
            case 'logical-or':
            case 'logical-xor':
            case 'minus-equal':
            case 'mod-equal':
            case 'mul-equal':
            case 'or-equal':
            case 'paamayim-nekudotayim':
            case 'plus-equal':
            case 'pow':
            case 'pow-equal':
            case 'sl':
            case 'sl-equal':
            case 'sr':
            case 'sr-equal':
            case 'xor-equal':
                return 'op '.$name;

            // Char
            case 'bad-character':
            case 'character':
                return 'char '.$name;

            // Const
            case 'class-c':
            case 'dir':
            case 'file':
            case 'func-c':
            case 'line':
            case 'method-c':
            case 'ns-c':
            case 'trait-c':
                return 'constant '.$name;

            // Function
            case 'empty':
            case 'eval':
            case 'halt-compiler':
            case 'isset':
            case 'list':
            case 'unset':
                return 'func '.$name;

            // Variable
            case 'num-string':
            case 'string-varname':
            case 'variable':
                return 'var '.$name;

            // String
            case 'encapsed-and-whitespace':
            case 'constant-encapsed-string':
                return 'string '.$name;

            // Number
            case 'dnumber':
                return 'float';
            case 'lnumber':
                return 'int';

            // Grammar
            case 'curly-open':
            case 'dollar-open-curly-braces':
            case 'double-arrow':
            case 'double-colon':
            case 'end-heredoc':
            case 'ns-separator':
            case 'object-operator':
            case 'start-heredoc':
            case 'whitespace':
                return 'g '.$name;

            // Comment
            case 'comment':
                return $name;
            case 'doc-comment':
                return 'comment '.$name;

            // Html
            case 'inline-html':
                return 'html';

            // Name
            case 'string':
                return 'name';
        }
    }


    /**
     * Export inline style tag
     */
    public function exportInlineStyles(): string
    {
        return '<style>'."\n".file_get_contents(__DIR__.'/resources/styles.css')."\n".'</style>';
    }
}
