<?php
class local_pdo
{
	private $pdo;
	public $error;
	private $inTransaction = false;

	public function __construct($sys = NULL)
	{
		$host = constant("DB_HOST");
		$user = constant("DB_USER");
		$pass = constant("DB_PASS");
		$database = constant("DB_NAME");

		try {
			$dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
			$this->pdo = new PDO($dsn, $user, $pass, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
				PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
			]);
		} catch (PDOException $e) {
			throw $e;  // Lançar exception em vez de morrer
		}
	}

	public function beginTransaction()
	{
		if (!$this->inTransaction) {
			$this->pdo->beginTransaction();
			$this->inTransaction = true;
		}
		return true;
	}

	public function commit()
	{
		if ($this->inTransaction) {
			$this->pdo->commit();
			$this->inTransaction = false;
		}
		return true;
	}

	public function rollback()
	{
		if ($this->inTransaction) {
			$this->pdo->rollBack();
			$this->inTransaction = false;
		}
		return true;
	}

	public function real_escape_string($string)
	{
		return trim($this->pdo->quote($string), "'");
	}

	public function select($fields, $table, $options)
	{
		$res = $this->my_query(
			sprintf(
				"SELECT %s FROM %s %s",
				$fields,
				$table,
				$options
			)
		);
		return $res;
	}

	public function insert($fields, $table, $options)
	{
		return $this->my_query(
			sprintf(
				"INSERT INTO %s SET %s %s",
				$table,
				$fields,
				$options
			)
		);
	}

	public function replace($fields, $table)
	{
		return $this->my_query(
			sprintf(
				"REPLACE INTO %s SET %s",
				$table,
				$fields
			)
		);
	}

	public function update($fields, $table, $options)
	{
		return $this->my_query(
			sprintf(
				"UPDATE %s SET %s %s",
				$table,
				$fields,
				$options
			)
		);
	}

	public function delete($table, $options)
	{
		return $this->my_query(
			sprintf(
				"DELETE FROM %s %s",
				$table,
				$options
			)
		);
	}

	public function my_query($query)
	{
		try {
			$stmt = $this->pdo->query($query);
			return $stmt;
		} catch (PDOException $e) {
			$this->error = $e->getMessage();
			if ($this->inTransaction) {
				$this->rollback();
			}
			die("SQL error: $query \n " . $this->error);
		}
	}

	public function query($query)
	{
		return $this->my_query($query);
	}

	public function recordcount($res)
	{
		if (!is_object($res)) return 0;
		try {
			return (int)$res->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public function result($res, $name, $position)
	{
		if ($res === false) return false;
		try {
			$rows = $res->fetchAll(PDO::FETCH_ASSOC);
			if ($position >= count($rows)) return false;
			return isset($rows[$position][$name]) ? $rows[$position][$name] : false;
		} catch (PDOException $e) {
			return false;
		}
	}

	public function results($res)
	{
		$obj = [];
		if (is_object($res)) {
			try {
				$obj = $res->fetchAll(PDO::FETCH_ASSOC);
			} catch (PDOException $e) {
				$this->error = $e->getMessage();
			}
		}
		return $obj;
	}

	public function fields_config($table)
	{
		$object = [];
		$res = $this->my_query(
			sprintf(
				"SHOW COLUMNS FROM %s",
				$table
			)
		);

		foreach ($this->results($res) as $key => $data) {
			if ($data["Key"] == "PRI") {
				$object[$data["Field"]]["PK"] = true;
			}
			if ($data["Key"] == "UNI") {
				$object[$data["Field"]]["UNI"] = true;
			}
			if (preg_match("/(?P<TYPE>\w+)\((?P<SIZE>.+)\)/", $data["Type"], $match)) {
				$object[$data["Field"]]["type"] = $match["TYPE"];
				$object[$data["Field"]]["size"] = $match["SIZE"];
			} else {
				$object[$data["Field"]]["type"] = $data["Type"];
			}

			if ($data["Default"] !== NULL) {
				$object[$data["Field"]]["default"] = $data["Default"];
			}
			if ($data["Extra"] == "auto_increment") {
				$object[$data["Field"]]["auto_increment"] = true;
			}
		}
		return $object;
	}

	public function getPdo()
	{
		return $this->pdo;
	}

	public function lastInsertId()
	{
		try {
			return (int)$this->pdo->lastInsertId();
		} catch (PDOException $e) {
			error_log('local_pdo::lastInsertId Error: ' . $e->getMessage());
			return 0;
		}
	}
}
