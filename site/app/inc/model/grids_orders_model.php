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
		$result = parent::__construct("grids_orders", $bd);
		// TTL reduzido: vínculos ordem-grid são criados e processados a cada ciclo do CRON
		$this->setCacheTTL(5);
		return $result;
	}
}
?>
