<?php
class grid_logs_model extends DOLModel
{
	protected $field = [
		"idx",
		"grids_id",
		"log_type",
		"event",
		"message",
		"data",
		"created_at"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("grid_logs", $bd);
	}
}
?>
