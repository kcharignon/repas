<?php

namespace Repas\Tests\Helper\InMemoryRepository;


use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SpyEventDispatcher implements EventDispatcherInterface
{
    public $eventDispatched = [];

    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        // TODO: Implement addListener() method.
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        // TODO: Implement addSubscriber() method.
    }

    public function removeListener(string $eventName, callable $listener): void
    {
        // TODO: Implement removeListener() method.
    }

    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        // TODO: Implement removeSubscriber() method.
    }

    public function getListeners(?string $eventName = null): array
    {
        // TODO: Implement getListeners() method.
        return [];
    }

    public function getListenerPriority(string $eventName, callable $listener): ?int
    {
        // TODO: Implement getListenerPriority() method.
        return null;
    }

    public function hasListeners(?string $eventName = null): bool
    {
        // TODO: Implement hasListeners() method.
        return false;
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->eventDispatched[] = $event;
        return $event;
    }

    public function lastEventDispatched(): ?object
    {
        return end($this->eventDispatched) ?? null;
    }
}
