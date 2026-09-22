<?php

namespace GlpiPlugin\Projectflow\Service;

use CommonDBTM;
use Document;
use Document_Item;
use Project;
use ProjectTask;
use Session;

class DocumentService
{
    /** @var array<class-string<CommonDBTM>> */
    private const ALLOWED_ITEMTYPES = [Project::class, ProjectTask::class];

    public function getForItem(string $itemtype, int $itemId): array
    {
        global $DB, $CFG_GLPI;

        $item = $this->loadItem($itemtype, $itemId);
        if (!$item || !$item->canViewItem()) {
            return [];
        }

        $documents = [];
        foreach ($DB->request([
            'FROM' => Document_Item::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemId],
            'ORDERBY' => ['id DESC'],
        ]) as $relation) {
            $document = new Document();
            $documentId = (int) ($relation['documents_id'] ?? 0);
            if ($documentId <= 0 || !$document->getFromDB($documentId) || !empty($document->fields['is_deleted'])) {
                continue;
            }
            // A document may be visible through the linked item even when the user does not
            // have global read access to the Documents inventory. Mirror GLPI's download check.
            if (!Document::canView() && !$document->canViewFile(['itemtype' => $itemtype, 'items_id' => $itemId])) {
                continue;
            }

            $filename = trim((string) ($document->fields['filename'] ?? ''));
            $name = trim((string) ($document->fields['name'] ?? ''));
            $download = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/')
                . '/front/document.send.php?docid=' . $documentId
                . '&itemtype=' . rawurlencode($itemtype)
                . '&items_id=' . $itemId;

            $documents[] = [
                'relation_id' => (int) $relation['id'],
                'id' => $documentId,
                'name' => $name !== '' ? $name : ($filename !== '' ? $filename : 'Documento #' . $documentId),
                'filename' => $filename,
                'mime' => (string) ($document->fields['mime'] ?? ''),
                'link' => trim((string) ($document->fields['link'] ?? '')),
                'date_mod' => $document->fields['date_mod'] ?? null,
                'url' => Document::getFormURLWithID($documentId),
                'download_url' => $download,
            ];
        }

        return $documents;
    }

    public function upload(string $itemtype, int $itemId, array $file, array $input = []): int|false
    {
        global $CFG_GLPI;

        $item = $this->loadItem($itemtype, $itemId);
        if (!$item || !$item->canViewItem() || !$item->canAddItem('Document') || !Document::canCreate()) {
            return false;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpUpload = (string) ($file['tmp_name'] ?? '');
        $original = $this->sanitizeFilename((string) ($file['name'] ?? ''));
        if ($error !== UPLOAD_ERR_OK || $tmpUpload === '' || $original === '' || !is_uploaded_file($tmpUpload)) {
            return false;
        }
        if (Document::isValidDoc($original) === '') {
            return false;
        }

        $maxMb = (float) ($CFG_GLPI['document_max_size'] ?? 0);
        $size = (int) ($file['size'] ?? 0);
        if ($maxMb > 0 && $size > (int) round($maxMb * 1024 * 1024)) {
            return false;
        }
        if (!is_dir(GLPI_TMP_DIR) || !is_writable(GLPI_TMP_DIR)) {
            return false;
        }

        $prefix = 'pf_' . bin2hex(random_bytes(8)) . '_';
        $tmpName = $prefix . $original;
        $target = rtrim(GLPI_TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tmpName;
        if (!move_uploaded_file($tmpUpload, $target)) {
            return false;
        }

        $entityId = method_exists($item, 'getEntityID') ? (int) $item->getEntityID() : (int) Session::getActiveEntity();
        $name = mb_substr(trim(strip_tags((string) ($input['name'] ?? ''))), 0, 255);
        if ($name === '') {
            $name = $original;
        }

        $recursive = method_exists($item, 'isRecursive') ? (int) $item->isRecursive() : 0;
        $document = new Document();
        $id = $document->add([
            'name' => $name,
            'entities_id' => $entityId,
            'users_id' => (int) Session::getLoginUserID(),
            'is_recursive' => $recursive,
            'itemtype' => $itemtype,
            'items_id' => $itemId,
            '_filename' => [$tmpName],
            '_prefix_filename' => [$prefix],
            '_only_if_upload_succeed' => 1,
        ]);

        @unlink($target);
        return $id ? (int) $id : false;
    }

    public function linkExisting(string $itemtype, int $itemId, int $documentId): bool
    {
        $item = $this->loadItem($itemtype, $itemId);
        $document = new Document();
        if (!$item || !$item->canViewItem() || !$item->canAddItem('Document')) {
            return false;
        }
        if (!$document->getFromDB($documentId) || !empty($document->fields['is_deleted'])) {
            return false;
        }
        // Linking an existing document must not turn knowledge of a numeric ID into access.
        // Require visibility on the document itself; canViewFile() is used only after a real link exists.
        if (!$document->canViewItem()) {
            return false;
        }
        $where = ['documents_id' => $documentId, 'itemtype' => $itemtype, 'items_id' => $itemId];
        if (countElementsInTable(Document_Item::getTable(), $where) > 0) {
            return true;
        }
        return (bool) (new Document_Item())->add($where);
    }

    public function unlink(string $itemtype, int $itemId, int $relationId): bool
    {
        $item = $this->loadItem($itemtype, $itemId);
        if (!$item || !$item->canUpdateItem()) {
            return false;
        }

        $relation = new Document_Item();
        if (!$relation->getFromDB($relationId)) {
            return false;
        }
        if ((string) $relation->fields['itemtype'] !== $itemtype || (int) $relation->fields['items_id'] !== $itemId) {
            return false;
        }

        // Only remove this association. The shared Document itself is kept intact.
        return (bool) $relation->delete(['id' => $relationId]);
    }

    private function loadItem(string $itemtype, int $itemId): ?CommonDBTM
    {
        if ($itemId <= 0 || !in_array($itemtype, self::ALLOWED_ITEMTYPES, true)) {
            return null;
        }
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->getFromDB($itemId)) {
            return null;
        }
        return $item;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '';
        $filename = preg_replace('/[^\pL\pN._()\- ]+/u', '_', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");
        return mb_substr($filename, 0, 180);
    }
}
