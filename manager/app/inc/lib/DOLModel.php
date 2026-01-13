<?php
class DOLModel extends rootOBJ
{
	protected $cache;
	protected $cacheEnabled = false;
	protected $cacheTTL = 3600;

	function __construct($table)
	{
		$c = new local_pdo();
		$this->set_con($c);
		$this->set_table($table);
		$this->set_schema($this->con->fields_config($this->table));
		$keys = [];
		foreach ($this->schema as $key => $value) {
			if (isset($value["PK"])) {
				$keys["pk"][] = $key;
			}
			if (isset($value["UNI"])) {
				$keys["UNI"][] = $key;
			}
		}
		$this->set_keys($keys);

		if (defined('REDIS_ENABLED') && REDIS_ENABLED && class_exists('RedisCache')) {
			try {
				$this->cache = RedisCache::getInstance();
				$this->cacheEnabled = $this->cache->isConnected();

				if (defined('REDIS_DEFAULT_TTL')) {
					$this->cacheTTL = REDIS_DEFAULT_TTL;
				}
			} catch (Exception $e) {
				error_log('DOLModel: Erro ao inicializar Redis - ' . $e->getMessage());
				$this->cacheEnabled = false;
			}
		}
	}

	public function save()
	{
		if (!isset($this->field)) {
			return false;
		}

		try {
			$this->con->beginTransaction();

			if (isset($this->field["idx"])) {
				unset($this->field["idx"]);
			}

			$ff = implode(" , ", $this->field);

			if (!preg_match("/'/", $ff)) {
				$this->con->rollback();
				return false;
			}

			$isUpdate = isset($this->filter) && is_array($this->filter) && count($this->filter) > 0;

			if ($isUpdate && count($this->filter) == 1 && ltrim(rtrim($this->filter[0])) == "active = 'yes'") {
				unset($this->filter);
				$isUpdate = false;
			}

			if ($isUpdate) {
				$fi = " WHERE " . implode(" AND ", $this->filter) . " ";
				$pa = isset($this->paginate) ? " LIMIT " . implode(" , ", $this->paginate) . " " : "";
				$ff .= ", modified_at = NOW(), modified_by = '" . $this->con->real_escape_string(isset($_SESSION[constant("cAppKey")]["credential"]["idx"]) ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0) . "'";

				$this->con->update($ff, $this->table, $fi . $pa);
				$this->con->commit();
				$this->clearTableCache();

				if (preg_match("/idx\s*=\s*['\"]?(\d+)['\"]?/i", $fi, $matches)) {
					return (int)$matches[1];
				}
				return true;
			} else {
				$ff .= ", created_at = NOW(), created_by = '" . $this->con->real_escape_string(isset($_SESSION[constant("cAppKey")]["credential"]["idx"]) ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0) . "'";

				$this->con->insert($ff, $this->table, null);

				$insertedId = (int)$this->con->lastInsertId();

				$this->con->commit();

				$this->clearTableCache();

				return $insertedId;
			}
		} catch (Exception $e) {
			$this->con->rollback();
			error_log('DOLModel::save Error: ' . $e->getMessage());
			return false;
		}
	}

	public function remove()
	{
		if (isset($this->filter)) {
			try {
				$this->con->beginTransaction();

				$fi = " WHERE " . implode(" AND ", $this->filter) . " ";
				$pa = isset($this->paginate) ? " LIMIT " . implode(" , ", $this->paginate) . " " : "";
				$ff = " active = 'no', removed_at = NOW(), removed_by = '" . $this->con->real_escape_string(isset($_SESSION[constant("cAppKey")]["credential"]["idx"]) ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0) . "'";
				$result = $this->con->update($ff, $this->table, $fi . $pa);

				$this->con->commit();

				$this->clearTableCache();

				return $result;
			} catch (Exception $e) {
				$this->con->rollback();
				return false;
			}
		}
		return false;
	}

	public function populate($data, $encode = false)
	{
		$array = [];
		foreach ($this->schema as $key => $value) {
			if (isset($data[$key])) {
				if (strtolower($data[$key])) {
					$array[$key] = sprintf(
						" %s = '%s' ",
						$key,
						$this->con->real_escape_string($data[$key])
					);
				}
			}
		}
		if (count($array)) {
			$this->set_field($array);
		}
	}

	public function return_data()
	{
		$this->load_data();
		return [$this->recordset, $this->data];
	}

	public function _list_data($value = "name", $filter = [], $key = "idx", $order = "")
	{
		$this->set_field([$key, $value]);
		$this->set_filter(count($filter) ? array_merge([" active = 'yes' "], $filter) : [" active = 'yes' "]);
		$this->set_order([$order == "" ? preg_replace("/.+ as (.+)$/", "$1", $value) . " ASC " : $order]);
		$this->load_data();
		return $this->data;
	}

	public function _current_data($filter = [], $fields = [], $attach = [], $attach_son = [], $availabled = false)
	{
		$field = [" idx ", " DATE_FORMAT( created_at , '%d/%m/%Y %H:%i' ) AS created_at ", " DATE_FORMAT( modified_at , '%d/%m/%Y %H:%i' ) AS modified_at "];
		if (!count($filter)) {
			$filter = [" idx = -1 "];
		}
		if (count($fields)) {
			$field = array_merge($field, $fields);
		}
		$this->set_field($field);
		$this->set_filter($filter);
		$this->set_paginate([1]);
		$this->load_data();

		if (count($attach)) {
			foreach ($attach as $k => $v) {
				$this->attach([$v["name"]], isset($v["direction"]) ? $v["direction"] : false, isset($v["specific"]) ? $v["specific"] : "");
			}
		}
		if (count($attach_son)) {
			foreach ($attach_son as $k => $v) {
				$classesfather = $v[0];
				$soon = $v[1];
				$classes = [$soon["name"]];
				$reverse_table = isset($soon["direction"]) ? $soon["direction"] : "";
				$options = isset($soon["options"]) ? $options : "";
				$this->attach_son($classesfather, $classes, $reverse_table, $options);
			}
		}
		if ($availabled != false && count($availabled)) {
			$this->data[0]["_availabe_attach"] = $availabled;
			foreach ($availabled as $key => $value) {
				if (isset($this->data[0][$key . "_attach"][0])) {
					foreach ($this->data[0][$key . "_attach"] as $k => $v) {
						$this->data[0]["_availabe_attach"][$key]["data"][] = $v["idx"];
					}
				}
			}
		}
		return current($this->data);
	}

	public function load_data()
	{
		$cacheKey = null;
		if ($this->cacheEnabled) {
			$ff = isset($this->field) ? implode(",", $this->field) : " * ";
			$fi = isset($this->filter) ? " WHERE " . implode(" AND ", $this->filter) . " " : "";
			$or = isset($this->order) ? " ORDER BY " . implode(" , ", $this->order) . " " : "";
			$gp = isset($this->group) ? " GROUP BY " . implode(" , ", $this->group) . " " : "";
			$pa = isset($this->paginate) ? " LIMIT " . implode(" , ", $this->paginate) . " " : "";

			$query = $this->table . ':' . $ff . $fi . $gp . $or . $pa;
			$cacheKey = 'query:' . md5($query);

			$cachedData = $this->cache->get($cacheKey);
			if ($cachedData !== null) {
				$this->set_data($cachedData['data']);
				$this->set_recordset($cachedData['recordset']);
				return;
			}
		}

		$ff = isset($this->field) ? implode(",", $this->field) : " * ";
		$fi = isset($this->filter) ? " WHERE " . implode(" AND ", $this->filter) . " " : "";
		$or = isset($this->order) ? " ORDER BY " . implode(" , ", $this->order) . " " : "";
		$gp = isset($this->group) ? " GROUP BY " . implode(" , ", $this->group) . " " : "";
		$pa = isset($this->paginate) ? " LIMIT " . implode(" , ", $this->paginate) . " " : "";
		$r = $this->con->select($ff, $this->table, $fi . $gp . $or . $pa);
		$data = $this->con->results($r);
		$recordset = $this->con->result($this->con->select(" COUNT( " . implode(",", $this->keys["pk"]) . ") AS q ", $this->table, $fi . $gp), "q", 0);

		$this->set_data($data);
		$this->set_recordset($recordset);

		if ($this->cacheEnabled && $cacheKey) {
			$this->cache->set($cacheKey, [
				'data' => $data,
				'recordset' => $recordset
			], $this->cacheTTL);
		}
	}

	public function attach($classes = [], $reverse_table = null, $options = null, $class_field = null)
	{
		$new_data = [];
		$_data = $this->data;
		foreach ($_data as $key => $value) {
			$new_data[$key] = $value;
			foreach ($classes as $class) {
				$r = $this->con->select(
					sprintf("%s_id AS k", $class),
					sprintf("%s_%s", $reverse_table ? $class : $this->table, $reverse_table ? $this->table : $class),
					sprintf(" WHERE active = 'yes' AND %s_id = '%d'", $this->table, $value["idx"])
				);
				$filter_key = ['0'];
				foreach ($this->con->results($r) as $key_r => $data) {
					$filter_key[] = "'" . $data["k"] . "'";
				}
				$r = $this->con->select(
					isset($class_field) ? implode(", ", $class_field) : "*",
					$class,
					sprintf(" WHERE active = 'yes' AND idx IN (%s) %s ", implode(",", array_unique($filter_key)), $options)
				);
				$new_data[$key][$class . "_attach"] = $this->con->results($r);
			}
		}
		$this->set_data($new_data);
	}

	public function join($name = null, $table = null, $fw_key = [], $options = null, $field = null)
	{
		$new_data = [];
		$_data = $this->get_data();
		foreach ($_data as $key => $value) {
			$new_data[$key] = $value;
			$flt = [" active = 'yes' "];
			foreach ((array)$fw_key as $fw_keys => $data_value) {
				if (isset($value[$data_value])) {
					$flt[] = $fw_keys . " = '" . $value[$data_value]  . "' ";
				}
			}
			if (count($flt) > 1 || ! empty($options)) {
				$r = $this->con->select(isset($field) ? implode(", ", $field) : "*", $table, " WHERE " . implode(" AND ", $flt) . strtr($options, ["#IDX#" => $value["idx"]]));
				$new_data[$key][$name . "_attach"] = $this->con->results($r);
			} else {
				$new_data[$key][$name . "_attach"] = [];
			}
		}
		$this->set_data($new_data);
	}

	public function attach_son($classesfather = "", $classes = [], $reverse_table = null, $options = null, $class_field = null)
	{
		if ($classesfather != "" && isset($classes) && count($classes)) {
			$new_data = [];
			$_data = $this->data;
			foreach ($_data as $key => $value) {
				$new_data[$key] = $value;
				if (isset($new_data[$key][$classesfather . "_attach"]) && count($new_data[$key][$classesfather . "_attach"])) {
					foreach ($new_data[$key][$classesfather . "_attach"] as $k => $v) {
						foreach ($classes as $class) {
							$r = $this->con->select(
								sprintf("%s_id AS k", $class),
								sprintf("%s_%s", $reverse_table ? $class : $classesfather, $reverse_table ? $classesfather : $class),
								sprintf(" WHERE active = 'yes' AND %s_id = '%d'", $classesfather, $this->con->real_escape_string($v["idx"]))
							);
							$filter_key = ['0'];
							foreach ($this->con->results($r) as $key_r => $data) {
								$filter_key[] = $data["k"];
							}
							$r = $this->con->select(
								isset($class_field[$class]) ? implode(", ", $class_field[$class]) : "*",
								$class,
								sprintf(" WHERE active = 'yes' AND idx IN ('%s') %s ", implode("','", array_unique($filter_key)), ($options != null ? preg_replace("/%s/im", $this->con->real_escape_string($value["idx"]), $options) : ""))
							);
							$new_data[$key][$classesfather . "_attach"][$k][$class . "_attach"] = $this->con->results($r);
						}
					}
				}
			}
			$this->set_data($new_data);
		}
	}

	public function attach_grandson($classesfather = "", $classeson = "", $classes = [], $reverse_table = null, $options = null, $class_field = null)
	{
		if ($classesfather != "" && $classeson != "" && isset($classes) && count($classes)) {
			$new_data = [];
			$_data = $this->data;
			foreach ($_data as $key => $value) {
				$new_data[$key] = $value;
				if (isset($new_data[$key][$classesfather . "_attach"]) && count($new_data[$key][$classesfather . "_attach"])) {
					foreach ($new_data[$key][$classesfather . "_attach"] as $k => $v) {
						if (isset($new_data[$key][$classesfather . "_attach"][$k][$classeson . "_attach"]) && count($new_data[$key][$classesfather . "_attach"][$k][$classeson . "_attach"])) {
							foreach ($new_data[$key][$classesfather . "_attach"][$k][$classeson . "_attach"] as $ks => $vs) {
								foreach ($classes as $class) {
									$r = $this->con->select(
										sprintf("%s_id AS k", $class),
										sprintf("%s_%s", $reverse_table ? $class : $classeson, $reverse_table ? $classeson : $class),
										sprintf(" WHERE active = 'yes' AND %s_id = '%d'", $classeson, $this->con->real_escape_string($vs["idx"]))
									);
									$filter_key = ['0'];
									foreach ($this->con->results($r) as $key_r => $data) {
										$filter_key[] = $data["k"];
									}
									$r = $this->con->select(
										isset($class_field[$class]) ? implode(", ", $class_field[$class]) : "*",
										$class,
										sprintf(" WHERE active = 'yes' AND idx IN ('%s') %s ", implode("','", array_unique($filter_key)), ($options != null ? preg_replace("/%s/im", $this->con->real_escape_string($value["idx"]), $options) : ""))
									);
									$new_data[$key][$classesfather . "_attach"][$k][$classeson . "_attach"][$ks][$class . "_attach"] = $this->con->results($r);
								}
							}
						}
					}
				}
			}
			$this->set_data($new_data);
		}
	}

	public function save_attach($info, $classes = [], $reverse_table = null)
	{
		try {
			$this->con->beginTransaction();

			foreach ($classes as $class) {
				if (isset($info["post"][$class . "_id"])) {
					$execute = $info["post"][$class . "_id"];
					$varexecute = [];
					if (is_array($execute) && count($execute)) {
						$varexecute = $execute;
					} elseif (!is_array($execute) && (int)$execute > 0) {
						$varexecute[] = $execute;
					}

					if (count($varexecute)) {
						$this->con->update(
							sprintf(" active = 'no' , removed_at = NOW() , removed_by = '%d' ", $this->con->real_escape_string(isset($_SESSION[constant("cAppKey")]["credential"]["idx"]) ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0)),
							sprintf(" %s_%s ", $reverse_table ? $class : $this->table, $reverse_table ? $this->table : $class),
							sprintf(" WHERE active='yes' AND %s_id = '%d'", $this->table, $this->con->real_escape_string($info["idx"]))
						);
						foreach ($varexecute as $var) {
							$sql = sprintf(
								"INSERT INTO %s (%s, %s, created_by, created_at) VALUES ('%d' , '%d', %d , NOW()) ON DUPLICATE KEY UPDATE active = 'yes', removed_at = NULL, removed_by = NULL, modified_at = NOW(), modified_by = '%d' ",
								sprintf(" %s_%s ", $reverse_table ? $class : $this->table, $reverse_table ? $this->table : $class),
								sprintf(" %s_id ", $class),
								sprintf(" %s_id ", $this->table),
								$this->con->real_escape_string($var),
								$this->con->real_escape_string($info["idx"]),
								$this->con->real_escape_string(isset($_SESSION[constant("cAppKey")]["credential"]["idx"]) ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0),
								$this->con->real_escape_string(isset($_SESSION[constant("cAppKey")]["credential"]["idx"]) ? $_SESSION[constant("cAppKey")]["credential"]["idx"] : 0)
							);
							$this->con->query($sql);
						}
					}
				}
			}

			$this->con->commit();

			$this->clearTableCache();

			return true;
		} catch (Exception $e) {
			$this->con->rollback();
			return false;
		}
	}

	protected function clearTableCache()
	{
		if ($this->cacheEnabled && $this->cache) {
			try {
				$pattern = 'query:*' . $this->table . '*';
				$this->cache->deletePattern($pattern);
			} catch (Exception $e) {
				error_log('DOLModel::clearTableCache Error: ' . $e->getMessage());
			}
		}
	}

	public function setCacheTTL(int $ttl)
	{
		$this->cacheTTL = $ttl;
	}

	public function setCacheEnabled(bool $enabled)
	{
		$this->cacheEnabled = $enabled && $this->cache && $this->cache->isConnected();
	}
}