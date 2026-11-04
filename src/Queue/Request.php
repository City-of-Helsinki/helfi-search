<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * One queued change, as stored in a request file:
 *
 *   {"url": "https://...", "action": "update"|"delete", "received_at": "<ISO 8601>"}
 */
final readonly class Request
{
    public function __construct(
        public string $url,
        public Action $action,
        public string $receivedAt,
    ) {
    }

    public static function now(string $url, Action $action): self
    {
        return new self($url, $action, gmdate('c'));
    }

    public function toJson(): string
    {
        return json_encode(
            ['url' => $this->url, 'action' => $this->action->value, 'received_at' => $this->receivedAt],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return self|null NULL if the file content isn't a valid request.
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['url'] ?? null) || !is_string($data['action'] ?? null)) {
            return null;
        }

        $action = Action::tryFrom($data['action']);
        if ($action === null) {
            return null;
        }

        return new self($data['url'], $action, is_string($data['received_at'] ?? null) ? $data['received_at'] : '');
    }
}
