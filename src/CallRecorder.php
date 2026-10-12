<?php
/**
 * Sits between SoapServer and MockService1 and remembers the last call —
 * operation name, decoded request params, the data returned (or the fault) —
 * so router.php can log them in readable form next to the raw XML.
 * SoapServer::setObject() dispatches to __call() for methods it can't find.
 * Created: 2026-10-12.
 */
class CallRecorder
{
    /** @var object */
    private $target;

    /** @var string|null */
    public $operation;

    /** @var mixed */
    public $params;

    /** @var mixed */
    public $result;

    /** @var Throwable|null */
    public $error;

    public function __construct($target)
    {
        $this->target = $target;
    }

    public function __call($name, $args)
    {
        $this->operation = $name;
        $this->params = $args[0] ?? null;
        try {
            return $this->result = $this->target->$name(...$args);
        } catch (Throwable $e) {
            $this->error = $e;
            throw $e;
        }
    }
}
