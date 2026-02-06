<?php
class grids_orders_model extends DOLModel
{
	protected $field = [
		"idx",
		"grids_id",
		"orders_id",
		"grid_level",
		"paired_order_id",
		"is_processed",
		"profit_usdc",
		"created_at"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("grids_orders", $bd);
	}
}
?>
