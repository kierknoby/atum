<?php
// SPDX-License-Identifier: GPL-3.0-or-later

final class AtumManifest
{
    /**
     * Parse Atum's intentionally small module.xml schema without requiring the
     * optional PHP XML extension. Keeping module.xml mirrors FreePBX module
     * packaging while preserving a minimal runtime dependency set.
     */
    public static function parse(string $file): array
    {
        $xml = @file_get_contents($file);
        if ($xml === false) {
            throw new RuntimeException('Unable to read module manifest: ' . $file);
        }
        self::validateStructure($xml, $file);

        $document = preg_replace('/^\s*<\?xml[^?]*\?>\s*/i', '', $xml) ?? $xml;
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) || !preg_match('/^\s*<module>.*<\/module>\s*$/si', $document)) {
            throw new RuntimeException('Module manifest uses unsupported or ambiguous XML: ' . $file);
        }
        $topLevel = preg_replace('/<(menuitems|depends|permissions)>.*?<\/\1>/si', '', $xml) ?? $xml;
        foreach (['rawname', 'name', 'version'] as $singleTag) {
            if (preg_match_all('/<' . $singleTag . '>.*?<\/' . $singleTag . '>/si', $topLevel) !== 1) {
                throw new RuntimeException('Module manifest must contain exactly one ' . $singleTag . ': ' . $file);
            }
        }
        $manifest = [
            'rawname' => self::tag($topLevel, 'rawname'),
            'name' => self::tag($topLevel, 'name'),
            'version' => self::tag($topLevel, 'version'),
            'publisher' => self::tag($topLevel, 'publisher'),
            'license' => self::tag($topLevel, 'license'),
            'category' => self::tag($topLevel, 'category'),
            'description' => self::tag($topLevel, 'description'),
            'permission' => self::tag($topLevel, 'permission', 'view'),
            'enabled' => strtolower(self::tag($topLevel, 'enabled', 'true')) !== 'false',
            'menuitems' => [],
            'depends' => [],
            'phpversion' => '',
            'extensions' => [],
            'permissions' => [],
        ];

        if (preg_match('/<menuitems>(.*?)<\/menuitems>/si', $xml, $menuBlock)) {
            preg_match_all('/<item>(.*?)<\/item>/si', $menuBlock[1], $items);
            foreach ($items[1] as $itemXml) {
                $manifest['menuitems'][] = [
                    'id' => self::tag($itemXml, 'id'),
                    'name' => self::tag($itemXml, 'name'),
                    'category' => self::tag($itemXml, 'category', $manifest['category']),
                    'sort' => (int) self::tag($itemXml, 'sort', '100'),
                    'permission' => self::tag($itemXml, 'permission', $manifest['permission']),
                    'children' => self::menuChildren($itemXml),
                ];
            }
        }

        if (preg_match('/<depends>(.*?)<\/depends>/si', $xml, $dependsBlock)) {
            $manifest['phpversion'] = self::tag($dependsBlock[1], 'phpversion');
            preg_match_all('/<module>(.*?)<\/module>/si', $dependsBlock[1], $depends);
            foreach ($depends[1] as $dependency) {
                $manifest['depends'][] = trim(strip_tags($dependency));
            }
            preg_match_all('/<extension>(.*?)<\/extension>/si', $dependsBlock[1], $extensions);
            $manifest['extensions'] = array_values(array_filter(array_map(static fn(string $v): string => trim(strip_tags($v)), $extensions[1] ?? [])));

        }

        if (preg_match('/<permissions>(.*?)<\/permissions>/si', $xml, $permissionBlock)) {
            preg_match_all('/<permission>(.*?)<\/permission>/si', $permissionBlock[1], $permissionItems);
            foreach ($permissionItems[1] as $permissionXml) {
                $id = self::tag($permissionXml, 'id');
                if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)?$/', $id)) {
                    throw new RuntimeException('Module manifest has an invalid permission identifier: ' . $file);
                }
                $manifest['permissions'][] = ['id' => $id, 'description' => self::tag($permissionXml, 'description')];
            }
        }

        if ($manifest['rawname'] === '') {
            throw new RuntimeException('Module manifest has no rawname: ' . $file);
        }
        if (!preg_match('/^[a-z0-9_-]+$/', $manifest['rawname'])) {
            throw new RuntimeException('Module manifest has an invalid rawname: ' . $file);
        }
        foreach ($manifest['menuitems'] as $item) {
            if ($item['id'] !== '' && !preg_match('/^[a-z0-9_-]+$/', $item['id'])) {
                throw new RuntimeException('Module manifest has an invalid menu item id: ' . $file);
            }
            foreach ($item['children'] as $child) {
                if (!preg_match('/^[a-z0-9_-]+$/', $child['id'])) {
                    throw new RuntimeException('Module manifest has an invalid menu child id: ' . $file);
                }
            }
        }
        foreach (['rawname', 'name', 'version'] as $required) {
            if ($manifest[$required] === '') {
                throw new RuntimeException('Module manifest has no ' . $required . ': ' . $file);
            }
        }

        return $manifest;
    }

    private static function tag(string $xml, string $tag, string $default = ''): string
    {
        if (!preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/si', $xml, $match)) {
            return $default;
        }

        return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** @return list<array{id:string,name:string}> */
    private static function menuChildren(string $itemXml): array
    {
        if (!preg_match('/<children>(.*?)<\/children>/si', $itemXml, $block)) {
            return [];
        }
        preg_match_all('/<child>(.*?)<\/child>/si', $block[1], $children);
        return array_map(static fn(string $child): array => [
            'id' => self::tag($child, 'id'),
            'name' => self::tag($child, 'name'),
        ], $children[1] ?? []);
    }

    private static function validateStructure(string $xml, string $file): void
    {
        $clean = preg_replace(['/^\s*<\?xml[^?]*\?>/i', '/<!--.*?-->/s'], '', $xml) ?? $xml;
        if (preg_match('/<!\[CDATA\[|<[^>]+\s+[A-Za-z_:][-A-Za-z0-9_:]*\s*=/', $clean)) {
            throw new RuntimeException('Module manifest uses unsupported XML features: ' . $file);
        }
        preg_match_all('/<\/?([A-Za-z_][A-Za-z0-9_-]*)>/', $clean, $matches, PREG_OFFSET_CAPTURE);
        $stack = [];
        foreach ($matches[0] as $token) {
            $text = $token[0]; $name = substr($text, 1, 1) === '/' ? substr($text, 2, -1) : substr($text, 1, -1);
            if (substr($text, 1, 1) === '/') {
                if (array_pop($stack) !== $name) { throw new RuntimeException('Module manifest has mismatched XML elements: ' . $file); }
            } else { $stack[] = $name; }
        }
        if ($stack !== []) { throw new RuntimeException('Module manifest has unclosed XML elements: ' . $file); }
        $withoutTags = preg_replace('/<\/?[A-Za-z_][A-Za-z0-9_-]*>/', '', $clean) ?? $clean;
        if (str_contains($withoutTags, '<') || str_contains($withoutTags, '>')) { throw new RuntimeException('Module manifest contains unsupported markup: ' . $file); }
    }
}
