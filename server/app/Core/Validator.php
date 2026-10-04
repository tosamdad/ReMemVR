<?php
namespace App\Core;

/**
 * 간단한 입력 검증.
 * $v = Validator::make($_POST, ['email' => 'required|email|max:191', 'password' => 'required|min:8|confirmed']);
 * if ($v->fails()) back_with_errors($v->errors());
 * 규칙: required, email, min:n, max:n(글자 수), numeric, integer, between:a,b(숫자), in:a,b,c, confirmed(항목_confirmation), date(Y-m-d)
 */
class Validator
{
    /** @var array */
    private $data;
    /** @var array */
    private $rules;
    /** @var array */
    private $labels;
    /** @var array<string, string> */
    private $errors = [];

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self();
        $v->data = $data;
        $v->rules = $rules;
        $v->labels = $labels;
        $v->run();

        return $v;
    }

    public function fails(): bool
    {
        return (bool) $this->errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** 검증한 항목만 공백을 정리해 돌려준다. */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            $value = isset($this->data[$field]) ? $this->data[$field] : null;
            $out[$field] = is_string($value) ? trim($value) : $value;
        }

        return $out;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = isset($this->data[$field]) ? $this->data[$field] : null;
            $value = is_string($value) ? trim($value) : $value;
            $label = isset($this->labels[$field]) ? $this->labels[$field] : $field;
            $empty = $value === null || $value === '' || (is_array($value) && !$value);
            foreach ($rules as $rule) {
                $param = null;
                if (strpos($rule, ':') !== false) {
                    list($rule, $param) = explode(':', $rule, 2);
                }
                if ($rule === 'required') {
                    if ($empty) {
                        $this->errors[$field] = self::josa($label, '을', '를') . ' 입력해 주세요.';
                        break;
                    }
                    continue;
                }
                if ($empty) {
                    continue;
                }
                $error = $this->check($rule, $param, $value, $field, $label);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }
    }

    private function check(string $rule, ?string $param, $value, string $field, string $label): ?string
    {
        $str = is_array($value) ? '' : (string) $value;
        switch ($rule) {
            case 'email':
                return filter_var($str, FILTER_VALIDATE_EMAIL) ? null : '올바른 이메일 주소를 입력해 주세요.';
            case 'min':
                return mb_strlen($str) >= (int) $param ? null : self::josa($label, '은', '는') . ' ' . (int) $param . '자 이상 입력해 주세요.';
            case 'max':
                return mb_strlen($str) <= (int) $param ? null : self::josa($label, '은', '는') . ' ' . (int) $param . '자 이하로 입력해 주세요.';
            case 'numeric':
                return is_numeric($str) ? null : self::josa($label, '은', '는') . ' 숫자로 입력해 주세요.';
            case 'integer':
                return preg_match('/^-?\d+$/', $str) ? null : self::josa($label, '은', '는') . ' 정수로 입력해 주세요.';
            case 'between':
                list($a, $b) = array_map('floatval', explode(',', (string) $param));
                return is_numeric($str) && (float) $str >= $a && (float) $str <= $b ? null : self::josa($label, '은', '는') . ' ' . $a . '에서 ' . $b . ' 사이로 입력해 주세요.';
            case 'in':
                return in_array($str, explode(',', (string) $param), true) ? null : $label . ' 값을 다시 골라 주세요.';
            case 'confirmed':
                $other = isset($this->data[$field . '_confirmation']) ? (string) $this->data[$field . '_confirmation'] : '';
                return hash_equals($str, $other) ? null : $label . ' 확인이 일치하지 않아요.';
            case 'date':
                $d = \DateTime::createFromFormat('Y-m-d', $str);
                return $d && $d->format('Y-m-d') === $str ? null : '올바른 날짜를 입력해 주세요.';
        }

        return null;
    }

    /** 마지막 글자의 받침에 맞춰 조사를 붙인다. 한글이 아니면 '을(를)'처럼 함께 적는다. */
    public static function josa(string $word, string $withFinal, string $withoutFinal): string
    {
        $last = mb_substr($word, -1);
        $code = $last === '' ? 0 : (int) mb_ord($last, 'UTF-8');
        if ($code < 0xAC00 || $code > 0xD7A3) {
            return $word . $withFinal . '(' . $withoutFinal . ')';
        }

        return $word . ((($code - 0xAC00) % 28) !== 0 ? $withFinal : $withoutFinal);
    }
}
