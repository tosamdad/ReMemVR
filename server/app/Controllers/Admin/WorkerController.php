<?php
namespace App\Controllers\Admin;

use App\Services\Jobs;
use App\Services\Worker;

/** 관리자 화면이 45초마다 부르는 작업 처리기 한 단계 실행(RMAdmin.tickWorker) */
class WorkerController
{
    /** POST /admin/api/worker/tick → ['ok', 'processed', 'remaining', 'locked', 'elapsed_ms', 'pending', 'running', 'failed_24h', 'done_today', 'by_type', ...] */
    public function tick(): array
    {
        require_admin();
        // 처리하는 동안 같은 관리자의 다른 화면 요청이 세션 잠금에 막히지 않게 한다.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        $result = Worker::run(15);

        return array_merge(['ok' => true], $result, Jobs::stats());
    }
}
