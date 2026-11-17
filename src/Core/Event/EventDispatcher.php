<?php

declare(strict_types=1);

namespace App\Core\Event;

use Closure;

class EventDispatcher
{
    /** @var array<string, array<Closure>> */
    private array $listeners = [];

    public function listen(string $eventName, Closure $listener): self
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        $this->listeners[$eventName][] = $listener;
        return $this;
    }

    public function dispatch(string|EventInterface $event, mixed $data = null): void
    {
        $eventName = $event instanceof EventInterface ? $event->getName() : $event;

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            $listener($event, $data);
        }
    }

    public function forget(string $eventName): self
    {
        unset($this->listeners[$eventName]);
        return $this;
    }

    public function getListeners(string $eventName): array
    {
        return $this->listeners[$eventName] ?? [];
    }
}
