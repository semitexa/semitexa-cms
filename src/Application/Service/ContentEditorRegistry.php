<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Cms\Attribute\AsContentEditor;
use Semitexa\Cms\Domain\Contract\ContentEditorInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

/**
 * Finds the editor that opens a given record.
 *
 * Scoped to the ambient tenant for the same reason the map is: an editor
 * declared for another site must not be reachable from this one's console, or
 * a ref typed into a URL becomes a way across the boundary.
 */
#[AsService]
final class ContentEditorRegistry
{
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    public function find(string $editorId): ?ContentEditorInterface
    {
        $editorId = trim($editorId);
        if ($editorId === '') {
            return null;
        }

        foreach ($this->editors() as $editor) {
            if ($editor->editorId() === $editorId) {
                return $editor;
            }
        }

        return null;
    }

    /**
     * @return list<ContentEditorInterface>
     */
    public function editors(): array
    {
        $current = $this->currentTenantId();
        $found = [];

        foreach ((new ClassDiscovery())->findClassesWithAttribute(AsContentEditor::class) as $class) {
            $declared = $this->declaredTenant($class);
            if ($declared !== '' && $declared !== $current) {
                continue;
            }

            $editor = $this->instantiate($class);
            if ($editor instanceof ContentEditorInterface) {
                $found[] = $editor;
            }
        }

        return $found;
    }

    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function declaredTenant(string $class): string
    {
        try {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsContentEditor::class);
        } catch (\Throwable) {
            return '';
        }

        return $attributes === [] ? '' : trim($attributes[0]->newInstance()->tenant);
    }

    private function instantiate(string $class): ?object
    {
        if (isset($this->container)) {
            try {
                return $this->container->get($class);
            } catch (\Throwable) {
                // Not every editor needs to be container-managed.
            }
        }

        try {
            return new $class();
        } catch (\Throwable) {
            return null;
        }
    }
}
