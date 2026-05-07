<?php

namespace App\Legacy;

use Doctrine\DBAL\Result;

class LegacyDbalResult
{
    private Result $result;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $bufferedRows = null;
    private int $bufferedOffset = 0;

    public function __construct(Result $result)
    {
        $this->result = $result;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetch(): array|false
    {
        if ($this->bufferedRows !== null) {
            $row = $this->bufferedRows[$this->bufferedOffset] ?? false;
            if ($row !== false) {
                $this->bufferedOffset++;
            }

            return $row;
        }

        return $this->result->fetchAssociative() ?: false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        if ($this->bufferedRows !== null) {
            return array_slice($this->bufferedRows, $this->bufferedOffset);
        }

        return $this->result->fetchAllAssociative();
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($column === 0) {
            $value = $this->result->fetchOne();

            return $value === false ? false : $value;
        }

        $row = $this->fetch();
        if ($row === false) {
            return false;
        }

        return array_values($row)[$column] ?? false;
    }

    public function rowCount(): int
    {
        if ($this->bufferedRows === null) {
            $this->bufferedRows = $this->result->fetchAllAssociative();
        }

        return count($this->bufferedRows);
    }
}
