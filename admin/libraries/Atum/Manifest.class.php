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

        $topLevel = preg_replace('/<(menuitems|depends)>.*?<\/\1>/si', '', $xml) ?? $xml;

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
}
