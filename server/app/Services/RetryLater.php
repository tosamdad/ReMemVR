<?php
namespace App\Services;

/**
 * 지금은 할 수 없지만 다른 작업이 끝나면 할 수 있는 일(예: ElevenLabs 목소리 자리가 날 때까지 기다림).
 * 작업 처리기는 이 예외를 받으면 시도 횟수를 쓰지 않고 $seconds 뒤로 미룬다.
 */
class RetryLater extends \RuntimeException
{
    /** @var int */
    public $seconds;

    public function __construct(string $message, int $seconds = 60)
    {
        parent::__construct($message);
        $this->seconds = max(5, $seconds);
    }
}
