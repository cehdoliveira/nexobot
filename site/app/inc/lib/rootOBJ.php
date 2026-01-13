<?php
if (!class_exists('rootOBJ')) {
	class rootOBJ
	{
		// Propriedades base
		public $data = [];

		// Propriedades usadas por DOLModel (evitar dynamic properties no PHP 8.2+)
		protected $con = null;
		protected $table = null;
		protected $schema = null;
		protected $keys = null;
		protected $paginate = null;
		protected $recordset = null;
		protected $filter = [];
		protected $field = [];

		public function __call($method, $paramters)
		{
			if (preg_match("/(?P<type>[sg]et)_(?P<method>\w+)/", $method, $match)) {
				$var = $match["method"];
				switch ($match["type"]) {
					case 'set':
						$this->$var = $paramters[0];
						break;
					case 'get':
						return $this->$var;
						break;
				}
			}
		}
		public function render($data, $format = NULL)
		{
			switch ($format) {
				case ".xml":
					header('Content-type: application/xml');
					render_xml(a_walk($data), "root");
					break;
				case ".json":
					header('Content-type: application/json');
					echo json_encode(a_walk($data));
					break;
				default:
					return $data;
					break;
			}
		}

		public function loadcurrent_data($filters = [], $fields = [], $attach = [], $attach_son = [], $availabled = false)
		{
			$field = count($fields) ? array_merge($this->field, $fields) : $this->field;
			$filter = count($filters) ? array_merge($this->filter, $filters) : $this->filter;
			return $this->_current_data($filter, $field, $attach, $attach_son, $availabled);
		}
	}
}
