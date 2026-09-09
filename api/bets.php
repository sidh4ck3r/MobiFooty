<?php
/**
 * Mobifooty — bet history / track record.
 *
 * GET  /api/bets.php            -> all bets + running totals for the user
 * POST /api/bets.php            -> add a bet {bet_type, stake, odds, result, placed_at, note}
 * POST /api/bets.php {action:'update-result', id, result} -> settle a bet
 * POST /api/bets.php {action:'delete', id}                -> remove a bet
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = current_user();
if (!$user) {
    json_err('Not signed in', 401);
}
$userId = (int)$user['id'];
$db = Database::getInstance()->getConnection();

/* ---------------- GET ---------------- */
if ($method === 'GET') {
    $stmt = $db->prepare('SELECT id, bet_type, stake, odds, potential_return, result, placed_at, note, created_at
                          FROM bets WHERE user_id = ? ORDER BY placed_at DESC, id DESC');
    $stmt->execute([$userId]);
    $bets = $stmt->fetchAll();

    $totals = [
        'bets_total'      => 0,
        'stake_total'     => 0.0,
        'return_total'    => 0.0,
        'profit_total'    => 0.0,
        'won'             => 0,
        'lost'            => 0,
        'pending'         => 0,
        'hit_rate'        => 0.0,
    ];
    foreach ($bets as $b) {
        $totals['bets_total']++;
        $totals['stake_total'] += (float)$b['stake'];
        if ($b['result'] === 'won') {
            $totals['won']++;
            $totals['return_total'] += (float)$b['potential_return'];
            $totals['profit_total'] += (float)$b['potential_return'] - (float)$b['stake'];
        } elseif ($b['result'] === 'lost') {
            $totals['lost']++;
        } else {
            $totals['pending']++;
        }
    }
    $settled = $totals['won'] + $totals['lost'];
    $totals['hit_rate'] = $settled > 0 ? round($totals['won'] / $settled, 4) : 0.0;
    $totals['stake_total'] = round($totals['stake_total'], 2);
    $totals['return_total'] = round($totals['return_total'], 2);
    $totals['profit_total'] = round($totals['profit_total'], 2);

    json_ok(['bets' => $bets, 'totals' => $totals]);
}

/* ---------------- POST ---------------- */
$body = body_json();
$action = $body['action'] ?? 'add';

if ($action === 'update-result') {
    $id = (int)($body['id'] ?? 0);
    $result = $body['result'] ?? null;
    if (!in_array($result, ['pending', 'won', 'lost'], true)) {
        json_err('Invalid result');
    }
    $stmt = $db->prepare('UPDATE bets SET result = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$result, $id, $userId]);
    json_ok(['updated' => $stmt->rowCount() > 0]);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    $stmt = $db->prepare('DELETE FROM bets WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    json_ok(['deleted' => $stmt->rowCount() > 0]);
}

if ($action === 'add') {
    $betType = in_array($body['bet_type'] ?? null, ['single', 'multiple'], true) ? $body['bet_type'] : 'single';
    $stake = (float)($body['stake'] ?? 0);
    $odds = (float)($body['odds'] ?? 0);
    $result = in_array($body['result'] ?? null, ['pending', 'won', 'lost'], true) ? $body['result'] : 'pending';
    $placedAt = $body['placed_at'] ?? date('Y-m-d');
    $note = trim($body['note'] ?? '');

    if ($stake <= 0 || $odds < 1.01) {
        json_err('Stake must be positive and odds at least 1.01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $placedAt)) {
        $placedAt = date('Y-m-d');
    }
    $potential = round($stake * $odds, 2);

    $stmt = $db->prepare('INSERT INTO bets (user_id, bet_type, stake, odds, potential_return, result, placed_at, note)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $betType, $stake, $odds, $potential, $result, $placedAt, $note ?: null]);

    json_ok(['id' => (int)$db->lastInsertId()], 201);
}

json_err('Unknown action', 400);