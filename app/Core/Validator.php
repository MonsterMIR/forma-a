<?php

declare(strict_types=1);

namespace App\Core;

class Validator
{
    private array $errors = [];
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? null;
        if ($value === null || trim((string)$value) === '') {
            $this->errors[$field] = "{$label} is required.";
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if (mb_strlen((string)$value) < $min) {
            $this->errors[$field] = "{$label} must be at least {$min} characters.";
        }
        return $this;
    }

    public function maxLength(string $field, int $max, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if (mb_strlen((string)$value) > $max) {
            $this->errors[$field] = "{$label} must not exceed {$max} characters.";
        }
        return $this;
    }

    public function email(string $field, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "{$label} must be a valid email address.";
        }
        return $this;
    }

    public function numeric(string $field, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !is_numeric($value)) {
            $this->errors[$field] = "{$label} must be a number.";
        }
        return $this;
    }

    public function positiveNumber(string $field, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if ($value !== '' && (!is_numeric($value) || (float)$value <= 0)) {
            $this->errors[$field] = "{$label} must be a positive number.";
        }
        return $this;
    }

    public function inList(string $field, array $allowed, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$label} has an invalid value.";
        }
        return $this;
    }

    public function date(string $field, string $label = ''): static
    {
        $label = $label ?: $field;
        $value = $this->data[$field] ?? '';
        if ($value !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                $this->errors[$field] = "{$label} must be a valid date (YYYY-MM-DD).";
            }
        }
        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return !empty($this->errors) ? array_values($this->errors)[0] : null;
    }
}