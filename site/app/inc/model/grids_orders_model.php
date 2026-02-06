<?php
class grids_orders_model extends DOLModel
{
	protected $field = [
		"idx",
		"grids_id",
		"orders_id",
		"created_at"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("grids_orders", $bd);
	}
}
?>
