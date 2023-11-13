<?php

namespace App\DTO;

class Message
{
    public function __construct(private readonly string $text)
    {
    }

    public function getText(): string
    {
        return $this->text;
    }
}
