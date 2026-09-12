<?php
namespace App\Core;

class Validator
{
    private array $errors = [];

    public function required($value, string $label): self
    {
        if ($value === null || trim((string)$value) === '') {
            $this->errors[] = $label . 'を入力してください。';
        }
        return $this;
    }

    public function maxLength($value, int $max, string $label): self
    {
        if ($value !== null && mb_strlen((string)$value) > $max) {
            $this->errors[] = $label . 'は' . $max . '文字以内で入力してください。';
        }
        return $this;
    }

    public function number($value, string $label): self
    {
        if ($value !== '' && $value !== null && !is_numeric($value)) {
            $this->errors[] = $label . 'は数字で入力してください。';
        }
        return $this;
    }

    public function positive($value, string $label): self
    {
        if ($value !== '' && $value !== null && is_numeric($value) && (float)$value < 0) {
            $this->errors[] = $label . 'に0より小さい数は入力できません。';
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
