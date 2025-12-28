<?php

namespace dispatcher;

use \PDO;

class SqliteModel
{
    protected PDO $pdo;
    private string $tableName;
    private array $decode = [];
    private array $unset = [];

    public function __construct(string $dbFile)
    {
        $this->pdo = new PDO("sqlite:{$dbFile}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function table(string $table): SqliteModel
    {
        if (empty($table)) return $this;
        $this->tableName = $table;
        $this->decode = [];
        $this->unset = [];
        return $this;
    }

    public function decode(string $field): SqliteModel
    {
        if (empty($field)) return $this;
        $this->decode = explode(',', $field);
        return $this;
    }

    public function unset(string $field): SqliteModel
    {
        if (empty($field)) return $this;
        $this->unset = explode(',', $field);
        return $this;
    }

    /**
     * @param array $data
     * @return int
     */
    public function insert(array $data): int
    {
        if (empty($this->tableName)) throw new \Error('未指定table');
        if (empty($data)) throw new \Error('插入数据不得为空');

        $value = [];
        $field = [];
        $fit = [];
        foreach ($data as $key => $val) {
            $field[] = $key;
            $fit[] = '?';
            if (is_array($val)) $val = json_encode($val, 320);
            $value[] = $val;
        }
        $sql = "INSERT INTO `{$this->tableName}` (" . implode(',', $field) . ") VALUES (" . implode(',', $fit) . ")";
        $stmt = $this->pdo->prepare($sql);
        $insert = $stmt->execute($value);
        if (!$insert) return 0;
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array $where
     * @param array $data
     * @return bool
     */
    public function update(array $where, array $data): bool
    {
        if (empty($this->tableName)) throw new \Error('未指定table');

//        $stmt = $this->pdo->prepare("UPDATE addresses SET name=?, phone=?, province=?, city=?, district=?, detail=? WHERE user_id=?");
//        $stmt->execute([$this->post['name'], $this->post['phone'], $this->post['province'], $this->post['city'], $this->post['district'], $this->post['detail'], $userId]);
        if (empty($where)) throw new \Error('Where数据不得为空');
        if (empty($data)) throw new \Error('更新数据不得为空');

        $wheres = [];
        $value = [];
        $field = [];

        foreach ($data as $key => $val) {
            if (preg_match('/^(\w+)([\^\+\-\|])$/', $key, $ms)) {
                $field[] = "{$ms[1]} = {$ms[1]} {$ms[2]} ?";
            } else {
                $field[] = "{$key} = ?";
            }
            $value[] = $val;
        }

        foreach ($where as $key => $val) {
            $build = $this->build_where($key, $val);
            $wheres[] = $build[0];
            array_push($value, ...$build[1]);
        }

        $sql = [];
        $sql[] = "UPDATE `{$this->tableName}`";
        $sql[] = "SET " . implode(', ', $field);
        $sql[] = "WHERE " . implode(' AND ', $wheres);

        $stmt = $this->pdo->prepare(implode(' ', $sql));
        return $stmt->execute($value);
    }

    public function all(array $where)
    {
        return $this->get($where, true);
    }

    public function get(array $where, bool $all = false)
    {
        if (empty($this->tableName)) throw new \Error('未指定table');

        $params = [];
        $field = [];
        foreach ($where as $key => $val) {
            $build = $this->build_where($key, $val);
            $field[] = $build[0];
            array_push($params, ...$build[1]);
        }

        $sql = [];
        $sql[] = "SELECT *";
        $sql[] = "FROM `{$this->tableName}`";
        if (!empty($where)) $sql[] = "WHERE " . implode(' AND ', $field);

        $stmt = $this->pdo->prepare(implode(' ', $sql));
        $stmt->execute($params);
        if ($all) {
            $value = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($value)) return [];
            if (!empty($this->unset)) {
                foreach ($value as &$val) {
                    foreach ($this->unset as $key) {
                        if (!empty($key)) unset($val[$key]);
                    }
                }
            }
            if (!empty($this->decode)) {
                foreach ($value as &$val) {
                    foreach ($this->decode as $key) {
                        if (isset($val[$key])) $val[$key] = json_decode($val[$key], true);
                    }
                }
            }
        } else {
            $value = $stmt->fetch(PDO::FETCH_ASSOC);
            if (empty($value)) return [];
            if (!empty($this->unset)) {
                foreach ($this->unset as $key) {
                    if (!empty($key)) unset($value[$key]);
                }
            }
            if (!empty($this->decode)) {
                foreach ($this->decode as $key) {
                    if (isset($value[$key])) $value[$key] = json_decode($value[$key], true);
                }
            }
        }
        if (empty($value)) return [];
        return $value;
    }

    private function build_where(string $key, $val): array
    {
        //$%^

        $chk = preg_match("/^(\w+)([!@#&<=>+-]{1,2})$/", $key, $ms);
        if (!$chk) return ["{$key} = ?", [$val]];

        $fd = $ms[1];

        switch ($ms[2]) {
            case '~':
                $val = "%{$val}%";
                return ["{$fd} like ?", [$val]];

            case '!':
                return ["{$fd} != ?", [$val]];

            case '#':
                if (!is_array($val)) {
                    throw new \InvalidArgumentException("between 的数据必须为array");
                }
                if (!isset($val[1])) {
                    throw new \InvalidArgumentException("between value[1] 不得为空");
                }
                return ["{$fd} BETWEEN ? and ? ", [$val[0], $val[1]]];

            case '@':
                if (!is_array($val) or empty($val)) {
                    throw new \InvalidArgumentException("where IN 的数据必须为不空的array");
                }
                $placeholders = implode(',', array_fill(0, count($val), '?'));
                return ["{$fd} IN ({$placeholders})", $val]; // 返回条件和参数数组

            case '&':
                return ["{$fd} & ? > 0", [$val]];

            default:
                return ["{$fd} {$ms[2]} ?", [$val]];
        }
    }

}