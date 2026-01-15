<?php

/**
 * Helper para gerenciar snapshots de saldo e tracking de crescimento patrimonial
 */
class WalletBalanceHelper
{
    /**
     * Salva snapshot do saldo ANTES de abrir um trade
     * @param int $tradeIdx ID do trade que está sendo aberto
     * @param float $balanceUsdc Saldo total em USDC
     * @return int|false ID do snapshot criado ou false em caso de erro
     */
    public static function snapshotBeforeTrade($tradeIdx, $balanceUsdc)
    {
        return self::createSnapshot($balanceUsdc, 'before_trade', $tradeIdx, 'Snapshot antes de abrir trade #' . $tradeIdx);
    }

    /**
     * Salva snapshot do saldo DEPOIS de fechar um trade
     * @param int $tradeIdx ID do trade que foi fechado
     * @param float $balanceUsdc Saldo total em USDC
     * @return int|false ID do snapshot criado ou false em caso de erro
     */
    public static function snapshotAfterTrade($tradeIdx, $balanceUsdc)
    {
        return self::createSnapshot($balanceUsdc, 'after_trade', $tradeIdx, 'Snapshot após fechar trade #' . $tradeIdx);
    }

    /**
     * Cria um snapshot manual do saldo
     * @param float $balanceUsdc Saldo total em USDC
     * @param string $notes Observações opcionais
     * @return int|false ID do snapshot criado ou false em caso de erro
     */
    public static function snapshotManual($balanceUsdc, $notes = '')
    {
        return self::createSnapshot($balanceUsdc, 'manual', null, $notes);
    }

    /**
     * Cria um snapshot e calcula crescimento percentual
     * @param float $balanceUsdc Saldo atual em USDC
     * @param string $type Tipo do snapshot (before_trade, after_trade, manual)
     * @param int|null $tradeIdx ID do trade relacionado
     * @param string $notes Observações
     * @return int|false ID do snapshot criado ou false em caso de erro
     */
    private static function createSnapshot($balanceUsdc, $type, $tradeIdx = null, $notes = '')
    {
        try {
            // Buscar último snapshot para calcular crescimento
            $walletModel = new walletbalances_model();
            $walletModel->set_filter(["active = 'yes'"]);
            $walletModel->set_order(["snapshot_at DESC"]);
            $walletModel->set_paginate([1, 0]);
            $walletModel->load_data();

            $previousBalance = null;
            $growthPercent = null;

            if (count($walletModel->data) > 0) {
                $lastSnapshot = $walletModel->data[0];
                $previousBalance = (float)$lastSnapshot['balance_usdc'];

                // Calcular crescimento percentual
                if ($previousBalance > 0) {
                    $growthPercent = (($balanceUsdc - $previousBalance) / $previousBalance) * 100;
                }
            }

            // Criar novo snapshot
            $newSnapshot = new walletbalances_model();
            $data = [
                'balance_usdc' => $balanceUsdc,
                'snapshot_type' => $type,
                'snapshot_at' => date('Y-m-d H:i:s'),
                'notes' => $notes,
                'active' => 'yes'
            ];

            if ($tradeIdx !== null) {
                $data['trade_idx'] = $tradeIdx;
            }

            if ($previousBalance !== null) {
                $data['previous_balance'] = $previousBalance;
            }

            if ($growthPercent !== null) {
                $data['growth_percent'] = round($growthPercent, 4);
            }

            $newSnapshot->populate($data);
            $snapshotId = $newSnapshot->save();

            return $snapshotId;
        } catch (Exception $e) {
            error_log("WalletBalanceHelper::createSnapshot Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calcula o crescimento patrimonial total desde o primeiro snapshot
     * @return array Informações de crescimento patrimonial
     */
    public static function getTotalGrowth()
    {
        try {
            $walletModel = new walletbalances_model();
            $walletModel->set_filter(["active = 'yes'"]);
            $walletModel->set_order(["snapshot_at ASC"]);
            $walletModel->load_data();

            if (count($walletModel->data) < 2) {
                return [
                    'has_data' => false,
                    'message' => 'Necessário pelo menos 2 snapshots para calcular crescimento'
                ];
            }

            $firstSnapshot = $walletModel->data[0];
            $lastSnapshot = $walletModel->data[count($walletModel->data) - 1];

            $initialBalance = (float)$firstSnapshot['balance_usdc'];
            $currentBalance = (float)$lastSnapshot['balance_usdc'];
            $difference = $currentBalance - $initialBalance;
            $growthPercent = $initialBalance > 0 ? (($difference / $initialBalance) * 100) : 0;

            return [
                'has_data' => true,
                'initial_balance' => $initialBalance,
                'current_balance' => $currentBalance,
                'difference' => $difference,
                'growth_percent' => round($growthPercent, 2),
                'first_snapshot_at' => $firstSnapshot['snapshot_at'],
                'last_snapshot_at' => $lastSnapshot['snapshot_at'],
                'total_snapshots' => count($walletModel->data)
            ];
        } catch (Exception $e) {
            error_log("WalletBalanceHelper::getTotalGrowth Error: " . $e->getMessage());
            return [
                'has_data' => false,
                'message' => 'Erro ao calcular crescimento: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Busca o último snapshot registrado
     * @return array|null Dados do último snapshot ou null
     */
    public static function getLastSnapshot()
    {
        try {
            $walletModel = new walletbalances_model();
            $walletModel->set_filter(["active = 'yes'"]);
            $walletModel->set_order(["snapshot_at DESC"]);
            $walletModel->set_paginate([1, 0]);
            $walletModel->load_data();

            return count($walletModel->data) > 0 ? $walletModel->data[0] : null;
        } catch (Exception $e) {
            error_log("WalletBalanceHelper::getLastSnapshot Error: " . $e->getMessage());
            return null;
        }
    }
}
