<?php
class grids_model extends DOLModel
{
	protected $field = [
		"idx",
		"users_id",
		"symbol",
		"status",
		"grid_levels",
		"lower_price",
		"upper_price",
		"grid_spacing_percent",
		"capital_allocated_usdc",
		"capital_per_level",
		"accumulated_profit_usdc",
		"current_price",
		"initial_capital_usdc",
		"peak_capital_usdc",
		"current_capital_usdc",
		"last_usdc_balance_usdc",
		"stop_loss_triggered",
		"stop_loss_triggered_at",
		"trailing_stop_triggered",
		"trailing_stop_triggered_at",
		"pending_shutdown_at",
		"pending_shutdown_reason",
		"is_processing",
		"last_monitor_at",
		"last_checked_at",
		"slide_count",
		"slide_count_down",
		"slide_count_up",
		"created_at"
	];
	protected $filter = ["active = 'yes'"];

	function __construct($bd = false)
	{
		$result = parent::__construct("grids", $bd);
		// TTL reduzido: o CRON lê esta tabela a cada minuto para decisões críticas
		$this->setCacheTTL(5);
		return $result;
	}

	/**
	 * Limpa o circuit breaker (pending_shutdown_at/reason → NULL).
	 * UPDATE direto: o populate() do DOLModel ignora valores null/vazios,
	 * então save() nunca grava NULL nessas colunas.
	 */
	public function clearPendingShutdown(int $gridId): void
	{
		$this->con->update(
			"pending_shutdown_at = NULL, pending_shutdown_reason = NULL, modified_at = NOW()",
			"grids",
			"WHERE idx = '" . (int)$gridId . "'"
		);
		$this->clearTableCache();
	}
}
?>
