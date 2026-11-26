<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Queries;

use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Exceptions\FolderMoveRefused;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Shared\Database\CzechCollation;

/**
 * The folder tree of the knowledge base, read once (R-19-05-2, D-01).
 *
 * `load()` costs exactly two queries however many folders there are: every folder (id, parent and name, in Czech
 * order) and one grouped count of the active general articles per folder. The body of an article is never read. The
 * tree is then built in PHP, so a screen that shows hundreds of folders never costs a query per folder, and the
 * move rules (`moveRefusal()`) read the same numbers the screens show. Both queries go through the models, so their
 * deny-all scopes stay applied.
 *
 * A node holds: `depth` (a root folder is level 1), `height` (a folder without subfolders is 1), `path` (the names
 * from the root), `count` (the active articles of the folder and of all its subfolders, archived ones left out) and
 * `hasChildren`. Siblings keep the Czech alphabetical order, children follow their parent.
 *
 * The depth rules use KnowledgeFolder::MAX_DEPTH, the one constant the database trigger is built with.
 */
final class FolderTree
{
    /**
     * @var list<array{id: string, parentId: string|null, name: string, depth: int, height: int, path: list<string>, count: int, hasChildren: bool}>
     */
    private array $nodes = [];

    /**
     * @var array<string, int> the position of each folder in
     */
    private array $positions = [];

    /**
     * @param  list<array{id: string, parentId: string|null, name: string}>  $folders  every folder, siblings in display order
     * @param  array<string, int>  $own  the active general articles of each folder itself
     */
    private function __construct(array $folders, array $own)
    {
        $children = [];

        foreach ($folders as $folder) {
            $children[$folder['parentId'] ?? ''][] = $folder;
        }

        $this->walk($children, $own, '', 1, []);
    }

    /**
     * Reads the tree: two queries.
     */
    public static function load(): self
    {
        $folders = [];

        $rows = CzechCollation::orderBy(KnowledgeFolder::query(), 'knowledge_folders.name')
            ->orderBy('knowledge_folders.id')
            ->get(['id', 'parent_id', 'name']);

        foreach ($rows as $row) {
            $folders[] = [
                'id' => (string) $row->getKey(),
                'parentId' => $row->parent_id === null ? null : (string) $row->parent_id,
                'name' => (string) $row->name,
            ];
        }

        $own = [];

        $counts = KnowledgeArticle::query()
            ->where('kind', ArticleKind::General->value)
            ->whereNotNull('folder_id')
            ->selectRaw('folder_id, count(*) as article_count')
            ->groupBy('folder_id')
            ->pluck('article_count', 'folder_id');

        foreach ($counts as $folderId => $count) {
            $own[(string) $folderId] = (int) $count;
        }

        return new self($folders, $own);
    }

    /**
     * Every folder, depth first, parents before their children, siblings in Czech order.
     *
     * @return list<array{id: string, parentId: string|null, name: string, depth: int, height: int, path: list<string>, count: int, hasChildren: bool}>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function has(string $id): bool
    {
        return isset($this->positions[$id]);
    }

    /**
     * @return array{id: string, parentId: string|null, name: string, depth: int, height: int, path: list<string>, count: int, hasChildren: bool}|null
     */
    public function node(string $id): ?array
    {
        return isset($this->positions[$id]) ? $this->nodes[$this->positions[$id]] : null;
    }

    /**
     * The folder and every folder below it, parents first. Empty for an unknown folder.
     *
     * @return list<string>
     */
    public function subtreeIds(string $id): array
    {
        if (! $this->has($id)) {
            return [];
        }

        $ids = [$id => true];

        // Parents come before their children, so one pass finds every descendant.
        foreach ($this->nodes as $node) {
            if ($node['parentId'] !== null && isset($ids[$node['parentId']])) {
                $ids[$node['id']] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }

    /**
     * The names from the root to the folder joined by " / ", or null for an unknown folder.
     */
    public function pathLabel(string $id): ?string
    {
        $node = $this->node($id);

        return $node === null ? null : implode(' / ', $node['path']);
    }

    /**
     * The level of the folder, a root folder being 1; 0 for an unknown folder.
     */
    public function depthOf(string $id): int
    {
        return $this->node($id)['depth'] ?? 0;
    }

    /**
     * The height of the folder's subtree, a folder without subfolders being 1; 0 for an unknown folder.
     */
    public function heightOf(string $id): int
    {
        return $this->node($id)['height'] ?? 0;
    }

    /**
     * Why the folder cannot move under the parent, or null when it can (null parent: it becomes a root).
     *
     * - FolderMoveRefused::CYCLE: the parent is the folder itself or lies in its subtree
     * - FolderMoveRefused::DEPTH: the parent's level plus the height of the moved subtree would pass the cap
     *
     * A root folder keeps a subtree that was valid below its old parent, so a move to the root is always allowed.
     */
    public function moveRefusal(string $folderId, ?string $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        if (in_array($parentId, $this->subtreeIds($folderId), true)) {
            return FolderMoveRefused::CYCLE;
        }

        if ($this->depthOf($parentId) + $this->heightOf($folderId) > KnowledgeFolder::MAX_DEPTH) {
            return FolderMoveRefused::DEPTH;
        }

        return null;
    }

    /**
     * Whether a sibling of the parent already has the name, regardless of case (the rule of the unique index).
     * `$exceptId` leaves one folder out (the folder being renamed or moved).
     */
    public static function nameTaken(?string $parentId, string $name, ?string $exceptId = null): bool
    {
        $query = KnowledgeFolder::query()->whereRaw('lower(name) = lower(?)', [$name]);

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    /**
     * Adds the children of one parent depth first and returns what they and their subtrees hold.
     *
     * @param  array<string, list<array{id: string, parentId: string|null, name: string}>>  $children  folders by parent id ('' for the roots)
     * @param  array<string, int>  $own
     * @param  list<string>  $path  the names of the ancestors
     * @return array{count: int, height: int} the articles of all these siblings with their subtrees, and the tallest subtree
     */
    private function walk(array $children, array $own, string $parentKey, int $depth, array $path): array
    {
        $count = 0;
        $height = 0;

        foreach ($children[$parentKey] ?? [] as $folder) {
            $position = count($this->nodes);
            $folderPath = [...$path, $folder['name']];

            $this->positions[$folder['id']] = $position;
            $node = [
                'id' => $folder['id'],
                'parentId' => $folder['parentId'],
                'name' => $folder['name'],
                'depth' => $depth,
                'height' => 1,
                'path' => $folderPath,
                'count' => 0,
                'hasChildren' => isset($children[$folder['id']]),
            ];
            $this->nodes[] = $node;

            $below = $this->walk($children, $own, $folder['id'], $depth + 1, $folderPath);

            $folderCount = ($own[$folder['id']] ?? 0) + $below['count'];
            $folderHeight = 1 + $below['height'];

            $node['count'] = $folderCount;
            $node['height'] = $folderHeight;
            $this->nodes[$position] = $node;

            $count += $folderCount;
            $height = max($height, $folderHeight);
        }

        return ['count' => $count, 'height' => $height];
    }
}
