<?php
class tradelogs_model extends DOLModel
{
	protected $field = ["idx", "trades_id", "log_type", "event", "message", "data"];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("tradelogs", $bd);
	}
}
