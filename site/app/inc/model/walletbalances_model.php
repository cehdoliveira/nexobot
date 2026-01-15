<?php
class walletbalances_model extends DOLModel
{
	protected $field = ["idx", "balance_usdc", "snapshot_type", "trade_idx", "growth_percent", "previous_balance", "snapshot_at", "notes"];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		return parent::__construct("walletbalances", $bd);
	}
}
