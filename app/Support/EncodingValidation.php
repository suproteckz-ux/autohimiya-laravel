<?php

namespace App\Support;

use Closure;

class EncodingValidation
{
    public static function cleanText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $issue = TextEncoding::issue($value);

            if (in_array($issue, ['invalid_utf8', 'replacement_character', 'repeated_question_marks', 'mojibake'], true)) {
                $fail('Поле содержит поврежденную кодировку или служебные символы ???.');
            }
        };
    }
}
