<?php
require_once 'JsonStreamingParser/Listener/ListenerInterface.php';

class ImportListener implements \JsonStreamingParser\Listener\ListenerInterface
{
    private $conn;
    private $batch = [];
    private $batchSize = 100;
    private $stack;
    private $key;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function startDocument(): void {
        $this->stack = [];
        $this->key = null;
    }

    public function endDocument(): void {
        $this->flush();
    }

    public function startObject(): void {
        $this->stack[] = [];
    }

    public function endObject(): void {
        $obj = array_pop($this->stack);
        if (empty($this->stack)) {
            $this->batch[] = $obj;
            if (count($this->batch) >= $this->batchSize) {
                $this->flush();
            }
        } else {
            $this->value($obj);
        }
    }

    public function startArray(): void {
        $this->stack[] = [];
    }

    public function endArray(): void {
        $arr = array_pop($this->stack);
        $this->value($arr);
    }

    public function key(string $key): void {
        $this->key = $key;
    }

    public function value($value) {
        $parent = array_pop($this->stack);
        if ($this->key) {
            $parent[$this->key] = $value;
            $this->key = null;
        } else {
            $parent[] = $value;
        }
        $this->stack[] = $parent;
    }

    public function whitespace(string $whitespace): void {}

    private function flush() {
        if (empty($this->batch)) {
            return;
        }
        $result = importJsonData($this->batch, $this->conn);
        if ($result['status'] === 'error') {
            // In a real application, you would handle this error more gracefully
            throw new \Exception("Error importing batch: " . $result['message']);
        }
        $this->batch = [];
    }
}
?>
