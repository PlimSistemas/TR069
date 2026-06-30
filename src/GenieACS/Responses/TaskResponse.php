<?php

namespace Plimsistemas\TR069\GenieACS\Responses;

class TaskResponse
{
    public function __construct(
        protected array $data
    ) {}

    public function getId(): ?string
    {
        return $this->data['_id'] ?? null;
    }

    public function getName(): ?string
    {
        return $this->data['name'] ?? null;
    }

    public function getStatus(): ?string
    {
        return $this->data['status'] ?? null;
    }

    public function isPending(): bool
    {
        return $this->getStatus() === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->getStatus() === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->getStatus() === 'fault';
    }

    public function getParameterValues(): array
    {
        return $this->data['parameterValues'] ?? [];
    }

    public function raw(): array
    {
        return $this->data;
    }
}
