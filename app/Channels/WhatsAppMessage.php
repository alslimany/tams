<?php

namespace App\Channels;

class WhatsAppMessage
{
    public ?string $recipient = null;

    public ?string $fileUrl = null;

    public ?string $fileMime = null;

    public function __construct(
        public readonly string $content,
    ) {}

    public static function create(string $content): static
    {
        return new static($content);
    }

    public function to(string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function withFile(string $url, string $mime = 'application/pdf'): static
    {
        $this->fileUrl = $url;
        $this->fileMime = $mime;

        return $this;
    }

    public function hasFile(): bool
    {
        return filled($this->fileUrl);
    }
}
