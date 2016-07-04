<?php
include('includes/connect.php');
include('includes/get_session.php');
$viewer_id = insert_pageview($thisuser);

$explore_mode = $uri_parts[2];

if (in_array($explore_mode, array('rounds','blocks','addresses','transactions'))) {
	if ($thisuser) $game_id = $thisuser['game_id'];
	else $game_id = get_site_constant('primary_game_id');
	
	$q = "SELECT * FROM games WHERE game_id='".$game_id."';";
	$r = run_query($q);
	$this_game = mysql_fetch_array($r);
	
	$last_block_id = last_block_id($this_game['game_id']);
	$current_round = block_to_round($last_block_id+1);
	
	$round = false;
	$block = false;
	$address = false;
	
	$mode_error = true;
	
	if ($explore_mode == "rounds") {
		$round_id = intval($uri_parts[3]);
		if ($round_id == 0) {
			$mode_error = false;
			$pagetitle = "Round Results - ".$this_game['name'];
		}
		else {
			$q = "SELECT * FROM cached_rounds r LEFT JOIN nations n ON r.winning_nation_id=n.nation_id WHERE r.game_id=".$this_game['game_id']." AND r.round_id='".$round_id."';";
			$r = run_query($q);
			if (mysql_numrows($r) == 1) {
				$round = mysql_fetch_array($r);
				$mode_error = false;
				$pagetitle = $this_game['name']." - Results of round #".$round['round_id'];
			}
		}
	}
	if ($explore_mode == "addresses") {
		$address_text = $uri_parts[3];
		$q = "SELECT * FROM addresses WHERE address='".mysql_real_escape_string($address_text)."';";
		$r = run_query($q);
		if (mysql_numrows($r) == 1) {
			$address = mysql_fetch_array($r);
			$mode_error = false;
			$pagetitle = $this_game['name']." Address: ".$address['address'];
		}
	}
	if ($explore_mode == "blocks") {
		if ($_SERVER['REQUEST_URI'] == "/explorer/blocks" || $_SERVER['REQUEST_URI'] == "/explorer/blocks/") {
			$mode_error = false;
			$pagetitle = $this_game['name']." - List of blocks";
		}
		else {
			$block_id = intval($uri_parts[3]);
			$q = "SELECT * FROM blocks WHERE game_id='".$this_game['game_id']."' AND block_id='".$block_id."';";
			$r = run_query($q);
			if (mysql_numrows($r) == 1) {
				$block = mysql_fetch_array($r);
				$mode_error = false;
				$pagetitle = $this_game['name']." Block #".$block['block_id'];
			}
		}
	}
	if ($explore_mode == "transactions") {
		$tx_hash = $uri_parts[3];
		$q = "SELECT * FROM webwallet_transactions WHERE tx_hash='".mysql_real_escape_string($tx_hash)."';";
		$r = run_query($q);
		if (mysql_numrows($r) == 1) {
			$transaction = mysql_fetch_array($r);
			$mode_error = false;
			$pagetitle = $this_game['name']." Transaction: ".$transaction['tx_hash'];
		}
	}
	
	if ($mode_error) $pagetitle = "EmpireCoin - Blockchain Explorer";
	
	include('includes/html_start.php');
	
	if ($thisuser) { ?>
		<div class="container" style="max-width: 1000px; padding-top: 10px;">
			<?php
			$account_value = account_coin_value($this_game['game_id'], $thisuser);
			include("includes/wallet_status.php");
			?>
		</div>
		<?php
	}
	?>
	<div class="container" style="max-width: 1000px;">
		<?php
		if ($mode_error) {
			echo "Error, you've reached an invalid page.";
		}
		else {
			if ($explore_mode == "rounds") {
				if (!$round) {
					?>
					<h1><?php echo $this_game['name']; ?> Round Results</h1>
					<div style="border-bottom: 1px solid #bbb; margin-bottom: 5px;" id="rounds_complete">
						<div id="rounds_complete_0">
							<?php
							$rounds_complete = rounds_complete_html($this_game, $current_round-1, 20);
							$last_round_shown = $rounds_complete[0];
							echo $rounds_complete[1];
							?>
						</div>
					</div>
					<center>
						<a href="" onclick="show_more_rounds_complete(); return false;" id="show_more_link">Show More</a>
					</center>
					<br/>
					<script type="text/javascript">
					$(document).ready(function() {
						last_round_shown = <?php echo $last_round_shown; ?>;
					});
					</script>
					<?php
				}
				else {
					if ($round['winning_nation_id'] > 0) echo "<h1>".$round['name']." wins round #".$round['round_id']."</h1>\n";
					else echo "<h1>Round #".$round['round_id'].": No winner</h1>\n";
					
					echo "<h3>".$this_game['name']."</h3>";
					?>
					<div class="row">
						<div class="col-md-6">
							<div class="row">
								<div class="col-sm-4">Total votes cast:</div>
								<div class="col-sm-8"><?php echo format_bignum($round['score_sum']/pow(10,8)); ?> votes</div>
							</div>
						</div>
					</div>
					<?php
					$max_score_sum = floor($round['score_sum']*get_site_constant('max_voting_fraction'));
					
					if ($thisuser) {
						$returnvals = my_votes_in_round($this_game, $round['round_id'], $thisuser['user_id']);
						$my_votes = $returnvals[0];
						$coins_voted = $returnvals[1];
					}
					else $my_votes = false;
					
					if ($my_votes[$round['winning_nation_id']] > 0) {
						if ($this_game['payout_weight'] == "coin") $payout_amt = (floor(100*750*$my_votes[$round['winning_nation_id']]['coins']/$round['winning_score'])/100);
						else $payout_amt = (floor(100*750*$my_votes[$round['winning_nation_id']]['coin_blocks']/$round['winning_score'])/100);
						
						echo "You won <font class=\"greentext\">+".$payout_amt." EMP</font> by voting ".format_bignum($my_votes[$round['winning_nation_id']]['coins']/pow(10,8))." coins";
						if ($this_game['payout_weight'] == "coin_block") echo " (".format_bignum($my_votes[$round['winning_nation_id']]['coin_blocks']/pow(10,8))." votes)";
						echo " for ".$round['name']."</font><br/>\n";
					}
					
					$from_block_id = (($round['round_id']-1)*10)+1;
					$to_block_id = ($round['round_id']*10);
					
					$q = "SELECT * FROM blocks WHERE game_id='".$game_id."' AND block_id >= '".$from_block_id."' AND block_id <= ".$to_block_id." ORDER BY block_id ASC;";
					$r = run_query($q);
					echo "Blocks in this round: ";
					while ($round_block = mysql_fetch_array($r)) {
						echo "<a href=\"/explorer/blocks/".$round_block['block_id']."\">".$round_block['block_id']."</a> ";
					}
					echo "<br/>\n";
					
					echo "<a href=\"/explorer/rounds/\">See all rounds</a><br/>";
					
					echo "<h2>Rankings</h2>";
					
					echo '<div class="row" style="font-weight: bold;">';
					echo '<div class="col-md-3">Empire</div>';
					echo '<div class="col-md-1" style="text-align: center;">Percent</div>';
					echo '<div class="col-md-3" style="text-align: center;">Coin Votes</div>';
					if ($thisuser) echo '<div class="col-md-3" style="text-align: center;">Your Votes</div>';
					echo '</div>'."\n";
					
					$winner_displayed = FALSE;
					for ($rank=1; $rank<=16; $rank++) {
						$q = "SELECT * FROM nations WHERE nation_id='".$round['position_'.$rank]."';";
						$r = run_query($q);
						if (mysql_numrows($r) == 1) {
							$ranked_nation = mysql_fetch_array($r);
							$nation_score = nation_score_in_round($this_game, $ranked_nation['nation_id'], $round['round_id']);
							
							echo '<div class="row';
							if ($nation_score > $max_score_sum) echo ' redtext';
							else if (!$winner_displayed && $nation_score > 0) { echo ' greentext'; $winner_displayed = TRUE; }
							echo '">';
							echo '<div class="col-md-3">'.$rank.'. '.$ranked_nation['name'].'</div>';
							echo '<div class="col-md-1" style="text-align: center;">'.round(100*$nation_score/$round['score_sum'], 2).'%</div>';
							echo '<div class="col-md-3" style="text-align: center;">'.round($nation_score/pow(10,8), 2).' votes</div>';
							if ($thisuser) {
								echo '<div class="col-md-3" style="text-align: center;">';
								
								$score_qty = $my_votes[$ranked_nation['nation_id']][$this_game['payout_weight'].'s'];
								
								echo number_format(floor($my_votes[$ranked_nation['nation_id']]['coin_blocks']/pow(10,8)*100)/100);
								if ($this_game['payout_weight'] == "coin") echo " coins";
								else echo " votes";
								
								echo ' ('.round(100*$score_qty/$nation_score, 3).'%)</div>';
							}
							echo '</div>'."\n";
						}
					}
					
					echo "<br/>\n";
					
					echo "<h2>Transactions</h2>";
					echo '<div style="border-bottom: 1px solid #bbb;">';
					for ($i=$from_block_id; $i<=$to_block_id; $i++) {
						echo "Block #".$i."<br/>\n";
						$q = "SELECT * FROM webwallet_transactions WHERE game_id='".$this_game['game_id']."' AND block_id='".$i."' AND amount > 0 ORDER BY transaction_id ASC;";
						$r = run_query($q);
						while ($transaction = mysql_fetch_array($r)) {
							echo render_transaction($transaction, FALSE, "");
						}
					}
					echo '</div>';
					
					echo "<br/>\n";
					echo "<br/>\n";
					
					if ($round['round_id'] > 1) { ?>
						<a href="/explorer/rounds/<?php echo $round['round_id']-1; ?>" style="display: inline-block; margin-right: 30px;">&larr; Previous Round</a>
						<?php
					}
					if ($round['round_id'] < $current_round-1) { ?>
						<a href="/explorer/rounds/<?php echo $round['round_id']+1; ?>">Next Round &rarr;</a>
						<?php
					}
				}
				echo "<br/><br/>\n";
			}
			else if ($explore_mode == "blocks") {
				if ($block) {
					$q = "SELECT COUNT(*), SUM(amount) FROM webwallet_transactions WHERE game_id='".$game_id."' AND block_id='".$block['block_id']."' AND amount > 0;";
					$r = run_query($q);
					$num_trans = mysql_fetch_row($r);
					$block_sum = $num_trans[1];
					$num_trans = $num_trans[0];
					
					$round_id = block_to_round($block['block_id']);
					$block_index = block_id_to_round_index($block['block_id']);
					
					echo "<h1>Block #".$block['block_id']."</h1>";
					echo "<h3>".$this_game['name']."</h3>";
					echo "This block contains $num_trans transactions totaling ".number_format($block_sum/pow(10,8), 2)." coins.<br/>\n";
					echo "This is block ".$block_index." of <a href=\"/explorer/rounds/".$round_id."\">round #".$round_id."</a><br/><br/>\n";
					
					echo '<div style="border-bottom: 1px solid #bbb;">';
					$q = "SELECT * FROM webwallet_transactions WHERE game_id='".$game_id."' AND block_id='".$block['block_id']."' AND amount > 0 ORDER BY transaction_id ASC;";
					$r = run_query($q);
					while ($transaction = mysql_fetch_array($r)) {
						echo render_transaction($transaction, FALSE, "");
					}
					echo '</div>';
					echo "<br/>\n";
					
					if ($block['block_id'] > 1) echo '<a href="/explorer/blocks/'.($block['block_id']-1).'" style="margin-right: 30px;">&larr; Previous Block</a>';
					echo '<a href="/explorer/blocks/'.($block['block_id']+1).'">Next Block &rarr;</a>';
					
					echo "<br/><br/>\n";
				}
				else {
					$q = "SELECT * FROM blocks WHERE game_id='".$game_id."' ORDER BY block_id ASC;";
					$r = run_query($q);
					
					echo "<h1>EmpireCoin - List of Blocks</h1>\n";
					echo "<h3>".$this_game['name']."</h3>";
					echo "<ul>\n";
					while ($block = mysql_fetch_array($r)) {
						echo "<li><a href=\"/explorer/blocks/".$block['block_id']."\">Block #".$block['block_id']."</a></li>\n";
					}
					echo "</ul>\n";
					
					echo "<br/><br/>\n";
				}
			}
			else if ($explore_mode == "addresses") {
				echo "<h3>EmpireCoin Address: ".$address['address']."</h3>\n";
				
				$q = "SELECT * FROM webwallet_transactions t, transaction_IOs i WHERE i.address_id='".$address['address_id']."' AND (t.transaction_id=i.create_transaction_id OR t.transaction_id=i.spend_transaction_id) GROUP BY t.transaction_id ORDER BY t.transaction_id ASC;";
				$r = run_query($q);
				
				echo "This address has been used in ".mysql_numrows($r)." transactions.<br/>\n";
				
				echo '<div style="border-bottom: 1px solid #bbb;">';
				while ($transaction_io = mysql_fetch_array($r)) {
					$block_index = block_id_to_round_index($transaction_io['block_id']);
					$round_id = block_to_round($transaction_io['block_id']);
					echo render_transaction($transaction_io, $address['address_id'], "Confirmed in the <a href=\"/explorer/blocks/".$transaction_io['block_id']."\">".date("jS", strtotime("1/".$block_index."/2015"))." block</a> of <a href=\"/explorer/rounds/".$round_id."\">round ".$round_id."</a>");
				}
				echo "</div>\n";
				
				echo "<br/><br/>\n";
			}
			else if ($explore_mode == "transactions") {
				echo "<h3>EmpireCoin Transaction: ".$transaction['tx_hash']."</h3>\n";
				$block_index = block_id_to_round_index($transaction['block_id']);
				$round_id = block_to_round($transaction['block_id']);
				echo '<div style="border-bottom: 1px solid #bbb;">';
				echo render_transaction($transaction, false, "Confirmed in the <a href=\"/explorer/blocks/".$transaction['block_id']."\">".date("jS", strtotime("1/".$block_index."/2015"))." block</a> of <a href=\"/explorer/rounds/".$round_id."\">round ".$round_id."</a>");
				echo "</div>\n";
				echo "<br/><br/>\n";
			}
		}
		?>
	</div>
	<?php

	include('includes/html_stop.php');
}
?>