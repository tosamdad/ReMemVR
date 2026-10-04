<?php
namespace App\Core;

/** abort() 가 던지는 예외. 앞단 컨트롤러가 상태 코드에 맞는 오류 화면이나 JSON 으로 바꾼다. */
class HttpException extends \RuntimeException
{
    /** @var int */
    private $status;

    public function __construct(int $status, string $message = '')
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
