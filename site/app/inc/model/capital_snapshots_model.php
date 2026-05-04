<?php
class capital_snapshots_model extends DOLModel
{
	protected $field = [
		"idx",
		"created_at",
		"grids_id",
		"total_capital_usdc",
		"usdc_balance",
		"btc_holding",
		"btc_price",
		"accumulated_spread_pnl",
		"active",
		"created_by"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		$result = parent::__construct("capital_snapshots", $bd);
		return $result;
	}
}
