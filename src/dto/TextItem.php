<?php

namespace lameco\rankroute\dto;

/**
 * One text item of the text flow: an addressable string n8n may rewrite.
 */
readonly class TextItem
{
    public const TYPE_PLAIN = 'plain';
    public const TYPE_HTML = 'html';

    /**
     * @param string $id Address, see {@see \lameco\rankroute\services\text\TextAddress}
     * @param string $type `plain` or `html`
     * @param string $value Current value
     * @param int|null $maxLength PlainText `charLimit` (255 for native titles), else null
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $value,
        public ?int $maxLength = null,
    ) {
    }

    /**
     * @return array{id: string, type: string, value: string, maxLength: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'maxLength' => $this->maxLength,
        ];
    }
}
