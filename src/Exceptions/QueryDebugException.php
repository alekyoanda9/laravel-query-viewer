<?php

namespace Sd1\QueryViewer\Exceptions;

use Exception;

/**
 * Exception "diharapkan" untuk kondisi bisnis package (query tidak ketemu,
 * bukan SELECT, EXPLAIN dimatikan, dsb). Ditangkap langsung di Controller
 * package dan diubah jadi JSON — jadi package TIDAK bergantung pada global
 * exception Handler aplikasi tempat ia dipasang.
 */
class QueryDebugException extends Exception
{
    /** @var int */
    protected $statusCode;

    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
