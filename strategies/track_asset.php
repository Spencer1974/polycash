<?php
$host_not_required = TRUE;
include(realpath(dirname(dirname(__FILE__)))."/includes/connect.php");
include(realpath(dirname(dirname(__FILE__)))."/includes/get_session.php");

$api_key = $_REQUEST['api_key'];

$q = "SELECT *, u.user_id AS user_id, g.game_id AS game_id FROM users u JOIN user_games ug ON u.user_id=ug.user_id JOIN games g ON ug.game_id=g.game_id JOIN user_strategies s ON ug.strategy_id=s.strategy_id LEFT JOIN featured_strategies fs ON s.featured_strategy_id=fs.featured_strategy_id WHERE ug.api_access_code=".$app->quote_escape($api_key).";";
$r = $app->run_query($q);

if ($r->rowCount() > 0) {
	$user_game = $r->fetch();
	$user = new User($app, $user_game['user_id']);
	$blockchain = new Blockchain($app, $user_game['blockchain_id']);
	$game = new Game($blockchain, $user_game['game_id']);
	
	$account_id = $user_game['account_id'];
	$fee = 0;
	
	$last_block_id = $blockchain->last_block_id();
	$mining_block_id = $last_block_id+1;
	$round_id = $game->block_to_round($mining_block_id);
	$coins_per_vote = $app->coins_per_vote($game->db_game);
	$fee_amount = $fee*pow(10, $blockchain->db_blockchain['decimal_places']);
	
	$btc_currency = $app->get_currency_by_abbreviation("BTC");
	$btc_price = $app->currency_price_at_time($btc_currency['currency_id'], 1, time());
	
	$hours_between_applications = 2;
	$sec_between_applications = 60*60*$hours_between_applications;
	$rand_sec_offset = rand(0, $sec_between_applications*2);
	
	if (time() > $user_game['time_next_apply'] || !empty($_REQUEST['force'])) {
		$account_q = "SELECT * FROM currency_accounts WHERE account_id='".$account_id."';";
		$account_r = $app->run_query($account_q);
		
		if ($account_r->rowCount() > 0) {
			$account = $account_r->fetch();
			
			$event_q = "SELECT * FROM events WHERE game_id='".$game->db_game['game_id']."'";
			$event_q .= " AND event_starting_block<=".$mining_block_id." AND event_final_block>=".$mining_block_id;
			$event_q .= " ORDER BY event_index ASC;";
			$event_r = $app->run_query($event_q);
			$db_events = $event_r->fetchAll();
			
			$num_events = count($db_events);
			
			if ($num_events > 0) {
				$selected_events = [];
				$events_by_ratio_diff = [];
				$event_info_by_id = [];
				
				for ($event_i=0; $event_i<count($db_events); $event_i++) {
					$options = $app->run_query("SELECT * FROM options op JOIN entities en ON op.entity_id=en.entity_id WHERE op.event_id='".$db_events[$event_i]['event_id']."' ORDER BY op.event_option_index ASC;")->fetchAll();
					
					$this_currency = $app->fetch_currency_by_id($options[0]['currency_id']);
					
					$buy_inflation_stake = $coins_per_vote*($options[0]['votes'] + $options[0]['unconfirmed_votes']);
					$buy_burn_stake = $options[0]['effective_destroy_score']+$options[0]['unconfirmed_effective_destroy_score'];
					$buy_stake = $buy_inflation_stake+$buy_burn_stake;
					
					$sell_inflation_stake = $coins_per_vote*($options[1]['votes'] + $options[1]['unconfirmed_votes']);
					$sell_burn_stake = $options[1]['effective_destroy_score']+$options[1]['unconfirmed_effective_destroy_score'];
					$sell_stake = $sell_inflation_stake+$sell_burn_stake;
					
					if ($this_currency['currency_id'] == $btc_currency['currency_id']) $market_price = $btc_price['price'];
					else {
						$latest_price = $app->currency_price_at_time($this_currency['currency_id'], $btc_currency['currency_id'], time());
						$market_price = $latest_price['price']*$btc_price['price'];
					}
					
					$market_ratio = ($market_price-$db_events[$event_i]['track_min_price'])/($db_events[$event_i]['track_max_price']+$db_events[$event_i]['track_min_price']);
					
					if ($buy_stake + $sell_stake == 0) {
						$event_info_by_id[$db_events[$event_i]['event_id']] = ['buy_stake'=>$buy_stake, 'sell_stake'=>$sell_stake, 'currency'=>$this_currency, 'market_ratio'=>$market_ratio];
						
						array_push($selected_events, $db_events[$event_i]);
					}
					else {
						$our_ratio = $buy_stake/($buy_stake+$sell_stake);
						$our_price = $our_ratio*($db_events[$event_i]['track_max_price']-$db_events[$event_i]['track_min_price'])+$db_events[$event_i]['track_min_price'];
						
						$ratio_diff = abs($our_ratio-$market_ratio);
						
						$events_by_ratio_diff[(string)$ratio_diff] = $db_events[$event_i];
						
						$event_info_by_id[$db_events[$event_i]['event_id']] = ['buy_stake'=>$buy_stake, 'sell_stake'=>$sell_stake, 'currency'=>$this_currency, 'our_ratio'=>$our_ratio, 'market_ratio'=>$market_ratio];
					}
				}
				
				krsort($events_by_ratio_diff);
				$events_needed = min(count($db_events)-count($selected_events), 2);
				$events_by_ratio_arr = array_values($events_by_ratio_diff);
				
				for ($add_i=0; $add_i<$events_needed; $add_i++) {
					array_push($selected_events, $events_by_ratio_arr[$add_i]);
				}
				
				$amount_mode = "per_event";
				if (!empty($_REQUEST['amount_mode']) && $_REQUEST['amount_mode'] == "inflation_only") $amount_mode = "inflation_only";
				
				if ($amount_mode == "per_event") {
					$frac_mature_bal = 0.1;
					
					$mature_balance = $user->mature_balance($game, $user_game);
					$coins_per_event = floor($mature_balance*$frac_mature_bal/count($selected_events));
				}
				else {
					list($user_votes, $votes_value) = $thisuser->user_current_votes($game, $blockchain->last_block_id(), $round_id, $user_game);
					$coins_per_event = ceil($votes_value/count($selected_events));
				}
				
				if ($coins_per_event > 0) {
					$total_cost = $coins_per_event*count($selected_events);
					
					$q = "SELECT *, SUM(gio.colored_amount) AS coins, SUM(gio.colored_amount)*(".$mining_block_id."-io.create_block_id) AS coin_blocks, SUM(gio.colored_amount*(".$round_id."-gio.create_round_id)) AS coin_rounds FROM transaction_game_ios gio JOIN transaction_ios io ON gio.io_id=io.io_id JOIN address_keys k ON io.address_id=k.address_id WHERE gio.is_resolved=1 AND io.spend_status IN ('unspent','unconfirmed') AND k.account_id='".$account['account_id']."' GROUP BY gio.io_id";
					$q .= " ORDER BY coins ASC;";
					$r = $app->run_query($q);
					
					$mandatory_bets = 0;
					$io_amount_sum = 0;
					$game_amount_sum = 0;
					$io_ids = array();
					$keep_looping = true;
					
					while ($keep_looping && $io = $r->fetch()) {
						$game_amount_sum += $io['coins'];
						$io_amount_sum += $io['amount'];
						
						if ($game->db_game['inflation'] == "exponential" && $game->db_game['exponential_inflation_rate'] > 0) {
							if ($game->db_game['payout_weight'] == "coin_block") $votes = $io['coin_blocks'];
							else if ($game->db_game['payout_weight'] == "coin_round") $votes = $io['coin_rounds'];
							$this_mandatory_bets = floor($votes*$coins_per_vote);
						}
						else $this_mandatory_bets = 0;
						
						$mandatory_bets += $this_mandatory_bets;
						array_push($io_ids, $io['io_id']);
						
						$burn_game_amount = $total_cost-$mandatory_bets;
						if ($amount_mode != "inflation_only" && $game_amount_sum >= $burn_game_amount*1.2) $keep_looping = false;
					}
					
					$q = "SELECT * FROM transaction_ios io JOIN addresses a ON io.address_id=a.address_id JOIN address_keys k ON a.address_id=k.address_id WHERE k.account_id='".$account['account_id']."' AND a.is_destroy_address=1 AND io.spend_status='unspent' ORDER BY io.amount DESC;";
					$r = $app->run_query($q);
					
					if ($r->rowCount() > 0) {
						$recycle_io = $r->fetch();
						array_push($io_ids, $recycle_io['io_id']);
						$io_amount_sum += $recycle_io['amount'];
					}
					
					if ($burn_game_amount < 0 || $burn_game_amount > $game_amount_sum) die("Failed to determine a valid burn amount (".$burn_game_amount." vs ".$game_amount_sum.").");
					
					$burn_address = $app->fetch_address_in_account($account['account_id'], 0);
					$separator_address = $app->fetch_address_in_account($account['account_id'], 1);
					$separator_frac = 0.25;
					
					$io_nonfee_amount = $io_amount_sum-$fee_amount;
					$burn_io_amount = ceil($io_nonfee_amount*$burn_game_amount/$game_amount_sum);
					$nonburn_io_amount = $io_nonfee_amount-$burn_io_amount;
					$io_amount_per_event = floor($nonburn_io_amount/$num_events);
					
					$io_amounts = array($burn_io_amount);
					$address_ids = array($burn_address['address_id']);
					$io_spent_sum = $burn_io_amount;
					
					$btc_currency = $app->get_currency_by_abbreviation("BTC");
					
					foreach ($selected_events as $db_event) {
						$this_event = new Event($game, $db_event, $db_event['event_id']);
						
						$event_starting_block = $game->blockchain->fetch_block_by_id($db_event['event_starting_block']);
						$event_final_block = $game->blockchain->fetch_block_by_id($db_event['event_final_block']);
						if ($event_final_block && !empty($event_final_block['time_mined'])) $event_to_time = $event_final_block['time_mined'];
						else $event_to_time = time();
						
						$info = $event_info_by_id[$db_event['event_id']];
						
						$buy_option = $app->run_query("SELECT * FROM options WHERE event_id='".$db_event['event_id']."' AND event_option_index=0;")->fetch();
						$sell_option = $app->run_query("SELECT * FROM options WHERE event_id='".$db_event['event_id']."' AND event_option_index=1;")->fetch();
						
						$event_total_payout = $db_event['destroy_score']+$db_event['sum_unconfirmed_destroy_score']+($db_event['sum_unconfirmed_score']+$db_event['sum_score'])*$coins_per_vote;
						
						$new_effectiveness_factor = $this_event->block_id_to_effectiveness_factor($mining_block_id);
						
						$this_stake = round($coins_per_event*$new_effectiveness_factor);
						
						$new_total_payout = $event_total_payout+$coins_per_event;
						
						$event_initial_stake = $info['buy_stake']+$info['sell_stake'];
						
						$new_event_stake = $event_initial_stake+$this_stake;
						
						$buy_option_stake = $buy_option['effective_destroy_score']+$buy_option['unconfirmed_effective_destroy_score']+($buy_option['votes']+$buy_option['unconfirmed_votes'])*$coins_per_vote;
						
						$ideal_buy_option_stake = $info['market_ratio']*$new_event_stake;
						
						$this_buy_amount = max(0, min($coins_per_event, ($ideal_buy_option_stake-$buy_option_stake)*($new_total_payout/$new_event_stake)));
						$this_buy_io_amount = round($io_amount_per_event*$this_buy_amount/$coins_per_event);
						$this_sell_io_amount = max(0, $io_amount_per_event-$this_buy_io_amount);
						
						$address_error = false;
						$thisevent_io_amounts = array();
						$thisevent_address_ids = array();
						
						if ($this_buy_io_amount > 0) {
							$buy_address = $app->fetch_address_in_account($account['account_id'], $buy_option['option_index']);
							
							if (!$buy_address) {
								$address_error = true;
								$app->output_message(8, "Cancelling transaction.. ".$buy_option['name']." has no address.", false);
								die();
							}
							
							$io_separator_amount = floor($this_buy_io_amount*$separator_frac);
							$io_regular_amount = $this_buy_io_amount-$io_separator_amount;
							
							array_push($thisevent_io_amounts, $io_regular_amount);
							array_push($thisevent_address_ids, $buy_address['address_id']);
							
							array_push($thisevent_io_amounts, $io_separator_amount);
							array_push($thisevent_address_ids, $separator_address['address_id']);
						}
						
						if ($this_sell_io_amount > 0) {
							$sell_address = $app->fetch_address_in_account($account['account_id'], $sell_option['option_index']);
							
							if (!$sell_address) {
								$address_error = true;
								$app->output_message(8, "Cancelling transaction.. ".$sell_option['name']." has no address.", false);
								die();
							}
							
							$io_separator_amount = floor($this_sell_io_amount*$separator_frac);
							$io_regular_amount = $this_sell_io_amount-$io_separator_amount;
							
							array_push($thisevent_io_amounts, $io_regular_amount);
							array_push($thisevent_address_ids, $sell_address['address_id']);
							
							array_push($thisevent_io_amounts, $io_separator_amount);
							array_push($thisevent_address_ids, $separator_address['address_id']);
						}
						
						if (!$address_error) {
							for ($i=0; $i<count($thisevent_io_amounts); $i++) {
								array_push($io_amounts, $thisevent_io_amounts[$i]);
								array_push($address_ids, $thisevent_address_ids[$i]);
								$io_spent_sum += $thisevent_io_amounts[$i];
							}
						}
					}
					$overshoot_amount = $io_spent_sum-$io_nonfee_amount;
					$io_amounts[count($io_amounts)-1] -= $overshoot_amount;
					
					$error_message = false;
					$transaction_id = $blockchain->create_transaction("transaction", $io_amounts, false, $io_ids, $address_ids, $fee_amount, $error_message);
					
					if ($transaction_id) {
						$strategy_q = "UPDATE user_strategies SET time_next_apply='".(time()+$rand_sec_offset)."' WHERE strategy_id='".$user_game['strategy_id']."';";
						$strategy_r = $app->run_query($strategy_q);
						
						$app->output_message(1, "Great, your transaction was submitted. <a href=\"/explorer/blockchains/".$blockchain->db_blockchain['url_identifier']."/transactions/".$transaction_id."/\">View Transaction</a>", false);
					}
					else {
						$app->output_message(7, "TX Error: ".$error_message, false);
					}
				}
				else $app->output_message(6, "Invalid coins_per_event.\n", false);
			}
			else $app->output_message(5, "There are no events running right now.\n", false);
		}
		else $app->output_message(4, "Invalid account ID.\n");
	}
	else $app->output_message(3, "Skipping.. this strategy was applied recently.\n", false);
}
else $app->output_message(2, "Error: the api_key you supplied does not match any user_game.\n", false);
?>