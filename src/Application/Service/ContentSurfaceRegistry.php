<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Cms\Attribute\AsContentCollection;
use Semitexa\Cms\Attribute\AsContentEditor;
use Semitexa\Cms\Domain\Contract\ContentCollectionInterface;
use Semitexa\Cms\Domain\Contract\ContentEditorInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

/**
 * Finds what opens a place: the editor for a record, or the rows behind a
 * collection.
 *
 * Both are scoped to the ambient tenant for the same reason the map is — a
 * surface declared for another site must not be reachable from this one's
 * console, or a ref typed into a URL becomes a way across the boundary.
 */
#[AsService]
final class ContentSurfaceRegistry
{
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    public function editor(string $editorId): ?ContentEditorInterface
    {
        $editorId = trim($editorId);
        if ($editorId === '') {
            return null;
        }

        /** @var ContentEditorInterface $editor */
        foreach ($this->discover(AsContentEditor::class, ContentEditorInterface::class) as $editor) {
            if ($editor->editorId() === $editorId) {
                return $editor;
            }
        }

        return null;
    }

    /**
     * Every editor this tenant has.
     *
     * @return list<ContentEditorInterface>
     */
    public function editors(): array
    {
        /** @var list<ContentEditorInterface> */
        return $this->discover(AsContentEditor::class, ContentEditorInterface::class);
    }

    /**
     * The rows behind a source like 'regmus:pages?type=event' — matched on the
     * part before the query, which is the only half this package understands.
     */
    public function collection(string $source): ?ContentCollectionInterface
    {
        $sourceId = trim(explode('?', trim($source), 2)[0]);
        if ($sourceId === '') {
            return null;
        }

        /** @var ContentCollectionInterface $collection */
        foreach ($this->discover(AsContentCollection::class, ContentCollectionInterface::class) as $collection) {
            if ($collection->sourceId() === $sourceId) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * The query part of a source, as the module's own vocabulary.
     *
     * @return array<string, string>
     */
    public static function filtersOf(string $source): array
    {
        $query = explode('?', trim($source), 2)[1] ?? '';
        if ($query === '') {
            return [];
        }

        parse_str($query, $parsed);

        $filters = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }

    /**
     * @param class-string $attribute
     * @param class-string $contract
     * @return list<object>
     */
    private function discover(string $attribute, string $contract): array
    {
        $current = $this->currentTenantId();
        $found = [];

        foreach ((new ClassDiscovery())->findClassesWithAttribute($attribute) as $class) {
            $declared = $this->declaredTenant($class, $attribute);
            if ($declared !== '' && $declared !== $current) {
                continue;
            }

            $instance = $this->instantiate($class);
            if ($instance instanceof $contract) {
                $found[] = $instance;
            }
        }

        return $found;
    }

    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    /** @param class-string $attribute */
    private function declaredTenant(string $class, string $attribute): string
    {
        try {
            $attributes = (new \ReflectionClass($class))->getAttributes($attribute);
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
