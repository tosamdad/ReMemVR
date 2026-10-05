<?php
namespace App\Services;

/**
 * 회원 플레이리스트. 완성된 동화(동화 + 가족 목소리)를 담아 차례로, 반복으로, 랜덤으로 듣는다.
 *   repeat_mode: off(한 번 듣고 끝) | all(전체 반복) | one(한 편 반복)
 *   shuffle: 1 이면 랜덤 순서. 순서는 재생 주소의 seed 로 정해 새로고침해도 같은 순서를 유지한다.
 * 플레이어 주소: /player/{story}?voice={voice}&pl={playlist}&pos={0부터 순번}&seed={랜덤 순서 값}&auto=1(바로 재생)
 */
class Playlists
{
    const REPEAT_MODES = ['off', 'all', 'one'];
    const NAME_MAX = 30;
    const MAX_PER_USER = 20;
    const MAX_ITEMS = 100;

    /** 반복 방식 이름 */
    public static function repeatLabel(string $mode): string
    {
        $map = ['off' => '한 번만', 'all' => '전체 반복', 'one' => '한 편 반복'];

        return isset($map[$mode]) ? $map[$mode] : $mode;
    }

    /** 회원의 플레이리스트 목록(담긴 편수, 들을 수 있는 편수, 첫 표지) */
    public static function forUser(int $userId): array
    {
        $lists = db_all('SELECT * FROM playlists WHERE user_id = ? ORDER BY updated_at DESC, id DESC', [$userId]);
        foreach ($lists as &$p) {
            $items = self::items((int) $p['id']);
            $p['count'] = count($items);
            $p['playable'] = count(array_filter($items, static function ($i) {
                return $i['playable'];
            }));
            $p['cover'] = $items ? $items[0] : null;
            $p['duration_ms'] = array_sum(array_map(static function ($i) {
                return $i['playable'] ? (int) $i['duration_ms'] : 0;
            }, $items));
        }
        unset($p);

        return $lists;
    }

    /** 회원 본인의 플레이리스트 하나. 없으면 null */
    public static function find(int $userId, int $playlistId): ?array
    {
        return db_one('SELECT * FROM playlists WHERE id = ? AND user_id = ?', [$playlistId, $userId]);
    }

    /**
     * 담긴 항목(순서대로). playable: 동화가 공개 중이고, 목소리가 남아 있고, 오디오가 완성됨.
     * 각 행: id, story_id, voice_profile_id, sort_order, title, category, cover_image_path, updated_at,
     *        voice_label, voice_icon, audio_id, duration_ms, playable
     */
    public static function items(int $playlistId): array
    {
        $rows = db_all(
            "SELECT pi.id, pi.story_id, pi.voice_profile_id, pi.sort_order,
                    s.id AS sid, s.title, s.category, s.cover_image_path, s.updated_at, s.status AS story_status, s.deleted_at AS story_deleted_at,
                    vp.label AS voice_label, vp.icon AS voice_icon, vp.deleted_at AS voice_deleted_at,
                    sa.id AS audio_id, sa.status AS audio_status, sa.file_path AS audio_file, sa.duration_ms
               FROM playlist_items pi
               JOIN stories s ON s.id = pi.story_id
               JOIN voice_profiles vp ON vp.id = pi.voice_profile_id
               LEFT JOIN story_audios sa ON sa.story_id = pi.story_id AND sa.voice_profile_id = pi.voice_profile_id
              WHERE pi.playlist_id = ?
              ORDER BY pi.sort_order, pi.id",
            [$playlistId]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['story_id'] = (int) $r['story_id'];
            $r['voice_profile_id'] = (int) $r['voice_profile_id'];
            $r['playable'] = $r['story_status'] === 'published' && $r['story_deleted_at'] === null && $r['voice_deleted_at'] === null
                && $r['audio_status'] === 'completed' && (string) $r['audio_file'] !== '';
        }
        unset($r);

        return $rows;
    }

    /** 플레이리스트를 만들고 id 를 돌려준다. */
    public static function create(int $userId, string $name): int
    {
        $name = self::cleanName($name);
        if ($name === '') {
            throw new \RuntimeException('플레이리스트 이름을 적어 주세요.');
        }
        if ((int) db_value('SELECT COUNT(*) FROM playlists WHERE user_id = ?', [$userId]) >= self::MAX_PER_USER) {
            throw new \RuntimeException('플레이리스트는 ' . self::MAX_PER_USER . '개까지 만들 수 있어요.');
        }

        return db_insert('playlists', ['user_id' => $userId, 'name' => $name, 'repeat_mode' => 'all', 'shuffle' => 0]);
    }

    public static function rename(int $userId, int $playlistId, string $name): void
    {
        $name = self::cleanName($name);
        if ($name === '') {
            throw new \RuntimeException('플레이리스트 이름을 적어 주세요.');
        }
        db_exec('UPDATE playlists SET name = ? WHERE id = ? AND user_id = ?', [$name, $playlistId, $userId]);
    }

    public static function delete(int $userId, int $playlistId): void
    {
        db_exec('DELETE FROM playlists WHERE id = ? AND user_id = ?', [$playlistId, $userId]);
    }

    /** 반복 방식과 랜덤 재생 저장 */
    public static function setMode(int $userId, int $playlistId, ?string $repeat, ?bool $shuffle): void
    {
        $sets = [];
        if ($repeat !== null && in_array($repeat, self::REPEAT_MODES, true)) {
            $sets['repeat_mode'] = $repeat;
        }
        if ($shuffle !== null) {
            $sets['shuffle'] = $shuffle ? 1 : 0;
        }
        if ($sets) {
            db_update('playlists', $sets, 'id = ? AND user_id = ?', [$playlistId, $userId]);
        }
    }

    /**
     * 완성된 동화를 담는다. 회원 본인의 목소리로 만든 완성 오디오만 담을 수 있다.
     * 반환: true(새로 담음) | false(이미 담겨 있음)
     */
    public static function addItem(int $userId, int $playlistId, int $storyId, int $voiceId): bool
    {
        if (!self::find($userId, $playlistId)) {
            throw new \RuntimeException('플레이리스트를 찾을 수 없어요.');
        }
        $ok = db_value(
            "SELECT sa.id FROM story_audios sa
               JOIN voice_profiles vp ON vp.id = sa.voice_profile_id AND vp.user_id = ? AND vp.deleted_at IS NULL
               JOIN stories s ON s.id = sa.story_id AND s.status = 'published' AND s.deleted_at IS NULL
              WHERE sa.story_id = ? AND sa.voice_profile_id = ? AND sa.status = 'completed' AND sa.file_path IS NOT NULL",
            [$userId, $storyId, $voiceId]
        );
        if (!$ok) {
            throw new \RuntimeException('완성된 동화만 담을 수 있어요.');
        }
        if (db_value('SELECT id FROM playlist_items WHERE playlist_id = ? AND story_id = ? AND voice_profile_id = ?', [$playlistId, $storyId, $voiceId])) {
            return false;
        }
        if ((int) db_value('SELECT COUNT(*) FROM playlist_items WHERE playlist_id = ?', [$playlistId]) >= self::MAX_ITEMS) {
            throw new \RuntimeException('플레이리스트 하나에는 ' . self::MAX_ITEMS . '편까지 담을 수 있어요.');
        }
        $next = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM playlist_items WHERE playlist_id = ?', [$playlistId]);
        db_insert('playlist_items', [
            'playlist_id' => $playlistId,
            'story_id' => $storyId,
            'voice_profile_id' => $voiceId,
            'sort_order' => $next,
        ]);
        self::touch($playlistId);

        return true;
    }

    public static function removeItem(int $userId, int $playlistId, int $itemId): void
    {
        if (!self::find($userId, $playlistId)) {
            throw new \RuntimeException('플레이리스트를 찾을 수 없어요.');
        }
        db_exec('DELETE FROM playlist_items WHERE id = ? AND playlist_id = ?', [$itemId, $playlistId]);
        self::touch($playlistId);
    }

    /** 항목 순서를 한 칸 올리거나(-1) 내린다(+1). */
    public static function moveItem(int $userId, int $playlistId, int $itemId, int $dir): void
    {
        if (!self::find($userId, $playlistId)) {
            throw new \RuntimeException('플레이리스트를 찾을 수 없어요.');
        }
        $ids = array_map('intval', array_column(db_all('SELECT id FROM playlist_items WHERE playlist_id = ? ORDER BY sort_order, id', [$playlistId]), 'id'));
        $pos = array_search($itemId, $ids, true);
        if ($pos === false) {
            return;
        }
        $to = $pos + ($dir < 0 ? -1 : 1);
        if ($to < 0 || $to >= count($ids)) {
            return;
        }
        $tmp = $ids[$to];
        $ids[$to] = $ids[$pos];
        $ids[$pos] = $tmp;
        db_tx(static function () use ($ids, $playlistId) {
            foreach ($ids as $i => $id) {
                db_exec('UPDATE playlist_items SET sort_order = ? WHERE id = ? AND playlist_id = ?', [$i + 1, $id, $playlistId]);
            }
        });
        self::touch($playlistId);
    }

    // ───────────────────────── 재생 순서 ─────────────────────────

    /**
     * 재생할 순서(들을 수 있는 항목만). 랜덤이면 seed 로 섞는다(같은 seed 면 같은 순서).
     */
    public static function order(array $playlist, int $seed): array
    {
        $list = array_values(array_filter(self::items((int) $playlist['id']), static function ($i) {
            return $i['playable'];
        }));
        if ((int) $playlist['shuffle'] === 1 && count($list) > 1) {
            // 0..n-1 을 seed 로 섞는다(Fisher-Yates, 선형 합동 난수). 서버 전역 난수에 영향을 주지 않는다.
            $state = ($seed & 0x7fffffff) ?: 1;
            for ($i = count($list) - 1; $i > 0; $i--) {
                $state = ($state * 1103515245 + 12345) & 0x7fffffff;
                $j = $state % ($i + 1);
                $tmp = $list[$i];
                $list[$i] = $list[$j];
                $list[$j] = $tmp;
            }
        }

        return $list;
    }

    /** 새 랜덤 순서 값 */
    public static function newSeed(): int
    {
        return random_int(1, 2000000000);
    }

    /** 재생 주소. $auto 면 플레이어가 열리자마자 재생을 시작한다(이어 듣기). */
    public static function playUrl(array $item, int $playlistId, int $pos, int $seed, bool $auto = false): string
    {
        $query = [
            'voice' => (int) $item['voice_profile_id'],
            'pl' => $playlistId,
            'pos' => $pos,
            'seed' => $seed,
        ];
        if ($auto) {
            $query['auto'] = 1;
        }

        return url('/player/' . (int) $item['story_id'], $query);
    }

    /**
     * 플레이어에 넘길 플레이리스트 정보와 다음 곡.
     * 지금 동화, 목소리가 순서의 pos 번째와 다르면 순서에서 찾아 맞춘다. 순서에 없으면 null.
     * 반환: ['playlist' => [...], 'next' => ['item', 'url', 'pos', 'seed']|null]
     */
    public static function context(array $playlist, int $storyId, string $voice, int $pos, int $seed): ?array
    {
        $order = self::order($playlist, $seed);
        $n = count($order);
        if ($n === 0) {
            return null;
        }
        $matches = static function ($i) use ($order, $storyId, $voice) {
            return isset($order[$i]) && $order[$i]['story_id'] === $storyId && (string) $order[$i]['voice_profile_id'] === $voice;
        };
        if (!$matches($pos)) {
            $pos = -1;
            foreach ($order as $i => $it) {
                if ($matches($i)) {
                    $pos = $i;
                    break;
                }
            }
            if ($pos < 0) {
                return null;
            }
        }
        $repeat = (string) $playlist['repeat_mode'];
        $next = null;
        if ($repeat === 'one') {
            $next = ['pos' => $pos, 'seed' => $seed];
        } elseif ($pos + 1 < $n) {
            $next = ['pos' => $pos + 1, 'seed' => $seed];
        } elseif ($repeat === 'all') {
            // 한 바퀴를 다 돌면 처음으로. 랜덤이면 다음 바퀴는 새로 섞는다.
            $next = ['pos' => 0, 'seed' => (int) $playlist['shuffle'] === 1 ? $seed + 1 : $seed];
        }
        if ($next !== null) {
            $nextOrder = $next['seed'] === $seed ? $order : self::order($playlist, $next['seed']);
            $item = $nextOrder[$next['pos']];
            $next['item'] = $item;
            $next['url'] = self::playUrl($item, (int) $playlist['id'], $next['pos'], $next['seed'], true);
        }

        return [
            'playlist' => [
                'id' => (int) $playlist['id'],
                'name' => (string) $playlist['name'],
                'pos' => $pos,
                'total' => $n,
                'repeat' => $repeat,
                'repeat_label' => self::repeatLabel($repeat),
                'shuffle' => (int) $playlist['shuffle'] === 1,
                'seed' => $seed,
                'url' => url('/playlists/' . (int) $playlist['id']),
            ],
            'next' => $next,
        ];
    }

    /** 회원의 완성 동화 중 이 플레이리스트에 아직 없는 것(담기 목록) */
    public static function addable(int $userId, int $playlistId): array
    {
        return db_all(
            "SELECT sa.story_id, sa.voice_profile_id, sa.duration_ms, s.title, s.category, s.cover_image_path, s.updated_at,
                    vp.label AS voice_label, vp.icon AS voice_icon
               FROM story_audios sa
               JOIN voice_profiles vp ON vp.id = sa.voice_profile_id AND vp.user_id = ? AND vp.deleted_at IS NULL
               JOIN stories s ON s.id = sa.story_id AND s.status = 'published' AND s.deleted_at IS NULL
              WHERE sa.status = 'completed' AND sa.file_path IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM playlist_items pi WHERE pi.playlist_id = ? AND pi.story_id = sa.story_id AND pi.voice_profile_id = sa.voice_profile_id)
              ORDER BY s.sort_order, s.id, vp.id",
            [$userId, $playlistId]
        );
    }

    private static function touch(int $playlistId): void
    {
        db_exec('UPDATE playlists SET updated_at = NOW() WHERE id = ?', [$playlistId]);
    }

    private static function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F<>]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        return mb_substr($name, 0, self::NAME_MAX);
    }
}
