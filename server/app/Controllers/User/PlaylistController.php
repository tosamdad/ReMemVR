<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Services\Playlists;

/**
 * 플레이리스트: 완성된 동화(동화 + 가족 목소리)를 담아 차례로, 반복으로, 랜덤으로 듣는다.
 * 재생은 플레이어(/player/{story}?voice=&pl=&pos=&seed=)가 맡고, 한 편이 끝나면 다음 편으로 넘어간다.
 */
class PlaylistController
{
    /** GET /playlists */
    public function index(): string
    {
        $user = $this->user();

        return view('user/playlists/index', [
            'playlists' => Playlists::forUser((int) $user['id']),
            'doneCount' => (int) db_value(
                "SELECT COUNT(*) FROM story_audios sa
                   JOIN voice_profiles vp ON vp.id = sa.voice_profile_id AND vp.user_id = ? AND vp.deleted_at IS NULL
                   JOIN stories s ON s.id = sa.story_id AND s.status = 'published' AND s.deleted_at IS NULL
                  WHERE sa.status = 'completed' AND sa.file_path IS NOT NULL",
                [(int) $user['id']]
            ),
        ]);
    }

    /** POST /playlists (name) */
    public function create(): void
    {
        $user = $this->user();
        try {
            $id = Playlists::create((int) $user['id'], Request::str('name'));
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/playlists');

            return;
        }
        flash('success', '플레이리스트를 만들었어요. 완성된 동화를 담아 보세요.');
        redirect('/playlists/' . $id);
    }

    /** POST /playlists/add (story_id, voice_id, playlist_id | new_name) : 내 동화에서 담기 */
    public function addFromLibrary(): void
    {
        $user = $this->user();
        $userId = (int) $user['id'];
        $playlistId = Request::int('playlist_id');
        try {
            if ($playlistId <= 0) {
                $playlistId = Playlists::create($userId, Request::str('new_name'));
            }
            $added = Playlists::addItem($userId, $playlistId, Request::int('story_id'), Request::int('voice_id'));
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect_back('/library');

            return;
        }
        $pl = Playlists::find($userId, $playlistId);
        $name = $pl ? $pl['name'] : '플레이리스트';
        flash($added ? 'success' : 'info', $added ? "'" . $name . "'에 담았어요." : "'" . $name . "'에 이미 담겨 있어요.");
        redirect_back('/library');
    }

    /** GET /playlists/{id} */
    public function show(string $id): string
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        $items = Playlists::items((int) $playlist['id']);
        $durationMs = 0;
        foreach ($items as $it) {
            if ($it['playable']) {
                $durationMs += (int) $it['duration_ms'];
            }
        }

        return view('user/playlists/show', [
            'playlist' => $playlist,
            'items' => $items,
            'playable' => count(array_filter($items, static function ($i) {
                return $i['playable'];
            })),
            'durationMs' => $durationMs,
            'addable' => Playlists::addable((int) $user['id'], (int) $playlist['id']),
        ]);
    }

    /** GET /playlists/{id}/play(?item=) : 새 순서(랜덤이면 새로 섞음)로 첫 편 또는 고른 편부터 재생 */
    public function play(string $id): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        $seed = Playlists::newSeed();
        $order = Playlists::order($playlist, $seed);
        if (!$order) {
            flash('info', '들을 수 있는 동화가 아직 없어요. 완성된 동화를 담아 주세요.');
            redirect('/playlists/' . (int) $playlist['id']);

            return;
        }
        $pos = 0;
        $itemId = Request::int('item');
        if ($itemId > 0) {
            foreach ($order as $i => $it) {
                if ($it['id'] === $itemId) {
                    $pos = $i;
                    break;
                }
            }
        }
        redirect(Playlists::playUrl($order[$pos], (int) $playlist['id'], $pos, $seed, true));
    }

    /** POST /playlists/{id}/rename (name) */
    public function rename(string $id): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        try {
            Playlists::rename((int) $user['id'], (int) $playlist['id'], Request::str('name'));
            flash('success', '이름을 바꿨어요.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/playlists/' . (int) $playlist['id']);
    }

    /** POST /playlists/{id}/delete */
    public function delete(string $id): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        Playlists::delete((int) $user['id'], (int) $playlist['id']);
        flash('success', "'" . $playlist['name'] . "' 플레이리스트를 지웠어요. 담긴 동화는 내 동화에 그대로 있어요.");
        redirect('/playlists');
    }

    /** POST /playlists/{id}/mode (repeat: off|all|one, shuffle: 0|1) */
    public function mode(string $id): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        $repeat = Request::str('repeat');
        $shuffle = input('shuffle');
        Playlists::setMode(
            (int) $user['id'],
            (int) $playlist['id'],
            $repeat !== '' ? $repeat : null,
            $shuffle === null || $shuffle === '' ? null : ((string) $shuffle === '1')
        );
        redirect('/playlists/' . (int) $playlist['id']);
    }

    /** POST /playlists/{id}/items (keys[]: "동화id:목소리id") */
    public function addItems(string $id): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        $raw = input('keys', []);
        $keys = is_array($raw) ? $raw : [$raw];
        $added = 0;
        $error = null;
        foreach ($keys as $key) {
            if (!preg_match('/^(\d+):(\d+)$/', (string) $key, $m)) {
                continue;
            }
            try {
                if (Playlists::addItem((int) $user['id'], (int) $playlist['id'], (int) $m[1], (int) $m[2])) {
                    $added++;
                }
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
                break;
            }
        }
        if ($added > 0) {
            flash('success', $added . '편을 담았어요.' . ($error ? ' ' . $error : ''));
        } else {
            flash($error ? 'error' : 'info', $error ?: '담을 동화를 골라 주세요.');
        }
        redirect('/playlists/' . (int) $playlist['id']);
    }

    /** POST /playlists/{id}/items/{item}/delete */
    public function removeItem(string $id, string $item): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        Playlists::removeItem((int) $user['id'], (int) $playlist['id'], (int) $item);
        flash('success', '플레이리스트에서 뺐어요.');
        redirect('/playlists/' . (int) $playlist['id']);
    }

    /** POST /playlists/{id}/items/{item}/move (dir: up|down) */
    public function moveItem(string $id, string $item): void
    {
        $user = $this->user();
        $playlist = $this->playlist($user, $id);
        Playlists::moveItem((int) $user['id'], (int) $playlist['id'], (int) $item, Request::str('dir') === 'up' ? -1 : 1);
        redirect('/playlists/' . (int) $playlist['id'] . '#item-' . (int) $item);
    }

    private function user(): array
    {
        $user = require_user();
        if (Auth::child() === null) {
            redirect('/onboarding');
        }

        return $user;
    }

    private function playlist(array $user, string $id): array
    {
        $playlist = Playlists::find((int) $user['id'], (int) $id);
        if (!$playlist) {
            abort(404, '플레이리스트를 찾을 수 없어요.');
        }

        return $playlist;
    }
}
