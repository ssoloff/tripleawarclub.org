<?php

/**
 * Class to handle player information.
 */

class CompPlayerHandler {

	var $db;

	/**
	 * Constructor
	 */
	function CompPlayerHandler() {
		// get database connection
		$this->db = XoopsDatabaseFactory::getDatabaseConnection();
	}

	/**
	 * Returns a player's profile from XOOPS and comp databases.
	 *
	 * @var $player_id XOOPS id of player's profile to return
	 * @var $comp_id optionally filter to one competition
	 * @var $max_status maximum status value the player may have in global profile
	 * 		(0=active, 1=short-term inactive, 2=long-term inactive, 3=delted)
	 * @return mixed array with player's profile
	 */
	function getPlayerProfile($player_id, $comp_id = null, $max_status = 1) {

		$profile = array();

		$comp_user_table = $this->db->prefix('comp_user_local');
		$comp_user_global_table = $this->db->prefix('comp_user_global');
		$comp_competition_table = $this->db->prefix('comp_competitions');
		$xoops_user_table = $this->db->prefix('users');
		// Get the global informaion
		$sql = "SELECT $xoops_user_table.*, $comp_user_global_table.* " .
				"FROM $comp_user_global_table, $xoops_user_table " .
				"WHERE $xoops_user_table.uid = $comp_user_global_table.xoops_user_id " .
					"AND $xoops_user_table.uid = $player_id " .
					"AND $comp_user_global_table.status <= $max_status";
		$result = $this->db->query($sql);

		// Found the correct, active player
		if( $this->db->getRowsNum($result) == 1 ){
			$profile = $this->db->fetchArray($result);

			// Modify date from stored number to string
			$profile['user_regdate'] = formatTimestamp($profile['user_regdate'], 's');

			// Get the player's karma
			$rating = $this->getPlayerKarmaRating($profile['uid']);
			$profile['num_negative'] = $rating['num_negative'];
			$profile['num_neutral'] = $rating['num_neutral'];
			$profile['num_positive'] = $rating['num_positive'];
			$profile['karma_rating'] = $rating['karma_rating'];

			// Get the country name
			include_once XOOPS_ROOT_PATH."/class/xoopslists.php";
			$country_list = XoopsLists::getCountryList();
			$profile['country_name'] = $country_list[$profile['country']];

			// Get the competition information
			$profile['competitions'] = array();
 			$sql = "SELECT $comp_user_table.* , $comp_competition_table.comp_name, " .
 				"IF(matches > 0, round(wins/(wins+losses)*100,1), 0) AS winpercent, " .
 				"IF(matches > 0, round(losses/(wins+losses)*100,1), 100) AS losspercent, " .
 				"allieswins+allieslosses AS alliesmatches, " .
 				"IF(allieswins+allieslosses > 0, round(allieswins/(allieswins+allieslosses)*100,1), 0) AS allieswinpercent, " .
 				"IF(allieswins+allieslosses > 0, round(allieslosses/(allieswins+allieslosses)*100,1), 100) AS allieslosspercent, " .
 				"axiswins+axislosses AS axismatches, " .
 				"IF(axiswins+axislosses > 0, round(axiswins/(axiswins+axislosses)*100,1), 0) AS axiswinpercent, " .
 				"IF(axiswins+axislosses > 0, round(axislosses/(axiswins+axislosses)*100,1), 100) AS axislosspercent " .
 				"FROM $comp_user_table, $comp_competition_table " .
				"WHERE $comp_user_table.xoops_user_id = $player_id " .
					"AND $comp_user_table.comp_id = $comp_competition_table.comp_id " .
					"AND $comp_user_table.status = 0";
			// Filter on competition id, if set
			if( isset($comp_id) ){
				$sql .= " AND $comp_user_table.comp_id = $comp_id";
			}

			$result = $this->db->query($sql);
			while( $row = $this->db->fetchArray($result) ){
				$row['rank'] = $this->getPlayerRank($row['rating']);
				$row['options'] = $this->getPlayerOptions($row['option_rules'], $row['option_luck'], $row['option_mode'], $row['nos'], $row['map'], $row['comp_id']);
				$row['played_matches'] = $this->getPlayerMatches($row['xoops_user_id'], $row['comp_id']);
				$row['challenges'] = $this->getPlayerChallenges($row['xoops_user_id'], $row['comp_id']);
				$profile['competitions'][] = $row;
			}
			unset($result);
		}

		return $profile;
	}

	/**
	 * Returns a player's challenges from the comp_challenges table.
	 * 
	 * @var $player_id player's id for challenges
	 * @var $comp_id competition id to retreive challenges from
	 * @var $current_only controls if completed challenges are returned
	 * @return mixed array of challenges from player and competition sorted by date
	 */
	function getPlayerChallenges($player_id, $comp_id, $current_only = true) {
		$matches_handler = xoops_getmodulehandler('matches');
		$ladder_handler = xoops_getmodulehandler('ladder');
	
		$challenges = array();

		$challenge_table = $this->db->prefix('comp_challenges');
		$xoops_user_table = $this->db->prefix('users');
		$comp_global_table = $this->db->prefix('comp_user_global');	
		if(!$comp_id){
		 	 // loop through all ladders
		 	 $ladders = $ladder_handler->getAllLadders();
			 $lids = array_keys($ladders);
			 $i=0;
			 foreach($lids as $lid){
			 
					$sql = "SELECT $challenge_table.*, challenger.uname AS challenger_name, challenged.uname AS challenged_name, " .
								"challenger.country AS challenger_country, challenged.country as challenged_country " .
							"FROM $challenge_table, " .
								"(SELECT uname, challenge_id, country FROM $challenge_table, $xoops_user_table, $comp_global_table " .
									"WHERE $challenge_table.challenger_id = $xoops_user_table.uid " .
										"AND $xoops_user_table.uid = $comp_global_table.xoops_user_id " .
										"AND $challenge_table.comp_id = $lid) AS challenger, " .
								"(SELECT uname, challenge_id, country FROM $challenge_table, $xoops_user_table, $comp_global_table " .
									"WHERE $challenge_table.challenged_id = $xoops_user_table.uid " .
										"AND $xoops_user_table.uid = $comp_global_table.xoops_user_id " .
										"AND $challenge_table.comp_id = $lid) AS challenged " .
							"WHERE challenged.challenge_id = challenger.challenge_id ";
								if( $current_only ){
									$sql .= "AND $challenge_table.chall_status < 2 ";
								}
								else{
									$sql .= "AND $challenge_table.chall_status < 3 ";
								}
								$sql .= "AND $challenge_table.challenge_id = challenger.challenge_id " .
								"AND ($challenge_table.challenged_id = $player_id OR $challenge_table.challenger_id = $player_id) " .
								"AND $challenge_table.comp_id = $lid " .
							"ORDER BY $challenge_table.chall_date DESC";
							
					$result = $this->db->query($sql);
					
					while( $row = $this->db->fetchArray($result) ){
						$challenges[$i] = $row;
						if($row['chall_status']==2){
							$match_result = $matches_handler->getMatchResult($player_id, $row['challenge_id']);
							$challenges[$i]['axis']=$match_result[0];
							$challenges[$i]['allies']=$match_result[1];
						}
						$i++;
					}
					unset($sql);
					unset($result);
					unset($result);			 
			 
			 }			 
		 
		} else {

			$sql = "SELECT $challenge_table.*, challenger.uname AS challenger_name, challenged.uname AS challenged_name, " .
						"challenger.country AS challenger_country, challenged.country as challenged_country " .
					"FROM $challenge_table, " .
						"(SELECT uname, challenge_id, country FROM $challenge_table, $xoops_user_table, $comp_global_table " .
							"WHERE $challenge_table.challenger_id = $xoops_user_table.uid " .
								"AND $xoops_user_table.uid = $comp_global_table.xoops_user_id " .
								"AND $challenge_table.comp_id = $comp_id) AS challenger, " .
						"(SELECT uname, challenge_id, country FROM $challenge_table, $xoops_user_table, $comp_global_table " .
							"WHERE $challenge_table.challenged_id = $xoops_user_table.uid " .
								"AND $xoops_user_table.uid = $comp_global_table.xoops_user_id " .
								"AND $challenge_table.comp_id = $comp_id) AS challenged " .
					"WHERE challenged.challenge_id = challenger.challenge_id ";
						if( $current_only ){
							$sql .= "AND $challenge_table.chall_status < 2 ";
						}
						else{
							$sql .= "AND $challenge_table.chall_status < 3 ";
						}
						$sql .= "AND $challenge_table.challenge_id = challenger.challenge_id " .
						"AND ($challenge_table.challenged_id = $player_id OR $challenge_table.challenger_id = $player_id) " .
						"AND $challenge_table.comp_id = $comp_id " .
					"ORDER BY $challenge_table.chall_date DESC";
					
			$result = $this->db->query($sql);
			$i=0;
			while( $row = $this->db->fetchArray($result) ){
				$challenges[$i] = $row;
				if($row['chall_status']==2){
					$match_result = $matches_handler->getMatchResult($player_id, $row['challenge_id']);
					$challenges[$i]['axis']=$match_result[0];
					$challenges[$i]['allies']=$match_result[1];
				}
				$i++;
			}
			unset($result);
		
		}
		return $challenges;
	}

	/**
	 * Returns a player's reported matches from the comp_matches table.
	 * 
	 * @var $player_id player's id to retreive matches
	 * @var $comp_id competition id to retreive matches from
	 * @var $side winning side to return matches about
	 * @return mixed array of matches from player and competition
	 */
	function getPlayerMatches($player_id, $comp_id, $side = "both"){

		$matches = array();

		// Set the side variables
		if( $side == "axis" ){
			$side = 0;
			$other_side = 1;
		}
		elseif( $side == "allies" ){
			$side = 1;
			$other_side = 0;
		}
		else{
			unset($side);
		}

		$match_table = $this->db->prefix('comp_matches');
		$xoops_user_table = $this->db->prefix('users');
		$comp_global_table = $this->db->prefix('comp_user_global');
		$sql = "SELECT $match_table.*, winner.uname AS winner_name, loser.uname AS loser_name, " .
					"winner.country AS winner_country, loser.country AS loser_country " .
				"FROM $match_table, " .
					"(SELECT uname, match_id, country FROM $match_table, $xoops_user_table, $comp_global_table " .
						"WHERE $match_table.winner_id = $xoops_user_table.uid " .
							"AND $xoops_user_table.uid = $comp_global_table.xoops_user_id " .
							"AND $match_table.comp_id = $comp_id) AS winner, " .
					"(SELECT uname, match_id, country FROM $match_table, $xoops_user_table, $comp_global_table " .
						"WHERE $match_table.loser_id = $xoops_user_table.uid " .
							"AND $xoops_user_table.uid = $comp_global_table.xoops_user_id " .
							"AND $match_table.comp_id = $comp_id) AS loser " .
				"WHERE winner.match_id = loser.match_id " .
					"AND $match_table.match_id = winner.match_id " .
					"AND ($match_table.winner_id = $player_id OR $match_table.loser_id = $player_id) ";
					if( isset($side) ){
						$sql .= " AND (" .
							"($match_table.side = $side AND $match_table.winner_id = $player_id) " .
							"OR ($match_table.side = $other_side AND $match_table.loser_id = $player_id)) ";
					}
				$sql .= "ORDER BY $match_table.match_date DESC";

		$result = $this->db->query($sql);

		while( $row = $this->db->fetchArray($result) ){
			// Get the side that won
			if( $row['side'] == 0 ){
				$row['side_name'] = 'Axis';
			}
			else{
				$row['side_name'] = 'Allies';
			}

			$matches[] = $row;
		}
		unset($result);

		return $matches;
	}

	/**
	 * Returns the players for the given competition id.
	 *
	 * @var $comp_id competition id's players to return
	 * @var $max_status maximum state a player may have in the global profile
	 * 		(0=active, 1=short-term inactive, 2=long-term inactive, 3=delted)
	 * @return mixed array of players from competition sorted by username
	 */
	function getActivePlayers($comp_id, $max_status = 1) {

		$players = array();

		// Create a Cache_Lite object
		//$cache_handler = xoops_getmodulehandler('cachelite');
		//$cacheoptions = array('cacheDir' => XOOPS_ROOT_PATH.'/modules/comp/class/cache/',  'lifeTime' => 3600);
		//$cache_handler->setOptions($cacheoptions);
		//$cacheid = 'Players'.$comp_id;
		// Check cache for information
		//if( $data = $cache_handler->get($cacheid) ){
		//	$players = unserialize($data);
		//	unset($data);
		//}
		// Check database for information
		//else {
			$comp_user_table = $this->db->prefix('comp_user_local');
			$comp_user_global_table = $this->db->prefix('comp_user_global');
			$xoops_user_table = $this->db->prefix('users');
 			$sql = "SELECT $xoops_user_table.*, $comp_user_table.*, $comp_user_global_table.country, $comp_user_global_table.status AS global_status " .
				"FROM $comp_user_table, $comp_user_global_table, $xoops_user_table " .
				"WHERE $xoops_user_table.uid = $comp_user_table.xoops_user_id " .
					"AND $xoops_user_table.uid = $comp_user_global_table.xoops_user_id " .
					"AND $comp_user_table.comp_id = $comp_id " .
					"AND $comp_user_global_table.status <= $max_status " .
					"AND $comp_user_table.status = 0 " .
				"ORDER BY $xoops_user_table.uname";

			$result = $this->db->query($sql);
			while( $row = $this->db->fetchArray($result) ){

				// Determine which options images to display
				$row['options'] = $this->getPlayerOptions($row['option_rules'], $row['option_luck'], $row['option_mode'], $row['nos'], $row['map'], $comp_id);

				// Get rank
				$row['rank'] = $this->getPlayerRank($row['rating']);

				// Get the karma
				$karma = $this->getPlayerKarmaRating($row['uid']);
				$row['num_negative'] = $karma['num_negative'];
				$row['num_neutral'] = $karma['num_neutral'];
				$row['num_positive'] = $karma['num_positive'];
				$row['karma_rating'] = $karma['karma_rating'];

				$players[] = $row;
			}
			unset($result);

			// Export information to cache
			//$players_export = serialize($players);
			//$cache_handler->save($players_export,$cacheid);
			unset($players_export);
			unset($comp_user_table);
			unset($comp_user_global_table);
			unset($xoops_user_table);
//	}

		return $players;
	}

	/**
	 * Return an array of decoded player options.
	 * 
	 * @var $rules the option_rules value stored in the database
	 * @var $luck the option_luck value stored in the database
	 * @var $mode the option_mode value stored in the database
	 * @var $nos the nos value stored in the database
	 * @var $comp_id the competition id
	 * @return mixed array of option name and descrition
	 */
	function getPlayerOptions($rules, $luck, $mode, $nos, $map, $comp_id){

		$options = array();

		if($comp_id!=6){ // this needs to be entirely re-worked.
		$options['rules'] = array();
		if( $rules < 3 ){
			$options['rules'][] = array('name'=>'4th', 'desc'=>_COMP_4TH);
		}
		if( $rules > 1 ){
			$options['rules'][] = array('name'=>'LHTR', 'desc'=>_COMP_LHTR);
		}
		} else {
			$options['rules'][] = array('name'=>'5th', 'desc'=>_COMP_5TH);
		}		
		
		$options['luck'] = array();
		if( $luck < 3 ){
			$options['luck'][] = array('name'=>'random', 'desc'=>_COMP_REGULARLUCK);
		}
		if( $luck > 1 ){
			$options['luck'][] = array('name'=>'ll', 'desc'=>_COMP_LL);
		}
		
		$options['mode'] = array();
		if( $mode < 3 ){
			$options['mode'][] = array('name'=>'pbem', 'desc'=>_COMP_PBEM);
		}
		if( $mode > 1 ){
			$options['mode'][] = array('name'=>'online', 'desc'=>_COMP_ONLINE);
		}
		
		$options['nos'] = array();
		if( $nos < 3 ){
			$options['nos'][] = array('name'=>'off', 'desc'=>_COMP_OFF);
		}
		if( $nos > 1 ){
			$options['nos'][] = array('name'=>'on', 'desc'=>_COMP_ON);
		}	
		
		$options['map'] = array();
		if( $map < 3 ){
			$options['map'][] = array('name'=>'1941', 'desc'=>_COMP_1941);
		}
		if( $map > 1 ){
			$options['map'][] = array('name'=>'1942', 'desc'=>_COMP_1942);
		}	
		
		return $options;
	}

	/**
	 * Returns a player's rank based on the provided rating (points).
	 * 
	 * @var $rating the player's current rating (points)
	 * @return the player's rank as a string  
	 */
	function getPlayerRank($rating) {

		switch(true){
			case ($rating > 1600):
				return _COMP_GENERAL_RANK;
			case ($rating > 1400):
				return _COMP_COLONEL_RANK;
			case ($rating > 1200):
				return _COMP_MAJOR_RANK;
			case ($rating > 1000):
				return _COMP_CAPTAIN_RANK;
			case ($rating > 800):
				return _COMP_LIEUTENANT_RANK;
			case ($rating > 600):
				return _COMP_SERGEANT_RANK;
			case ($rating > 400):
				return _COMP_CORPORAL_RANK;
			default:
				return _COMP_PRIVATE_RANK;
		}
	}

	/**
	 * Returns a players "karma" rating in percent and the number of positive, negative and neutral ratings.
	 *  - If a player has no ratings, the player gets a 100%
	 *
	 * @var $user_id player to get rating for
	 * @param array $return ['-1' = number of negative ratings, ['0'] = #neutral ratings, ['1'] = #positive ratings, ['overall_rating'] = rating in %]
	 * @return array player rating rounded to one decimal point
	 */
	function getPlayerKarmaRating($user_id) {

		$sql = "SELECT rating, COUNT(rating) AS counter
					FROM " .$this->db->prefix('comp_rating') ."
					WHERE rated_id = '$user_id' 
						GROUP BY rating";

		$result = $this->db->query($sql);
		$return = array();

		while( $row = $this->db->fetchArray($result) ){
			switch($row['rating']){
				case '-1':
					$return['num_negative'] = $row['counter'];
					break;
				case '0':
					$return['num_neutral'] = $row['counter'];
					break;
				case '1':
					$return['num_positive'] = $row['counter'];
					break;
			}
		}

		// add missing values if they don't exist in the database
		if (empty($return['num_negative'])) {
			$return['num_negative'] = 0;
		}
		if (empty($return['num_neutral'])) {
			$return['num_neutral'] = 0;
		}
		if (empty($return['num_positive'])) {
			$return['num_positive'] = 0;
		}

		// if any positive ratings exist
		if ($return['num_positive'] > 0) {
			$return['karma_rating'] = round( (($return['num_positive']) / ($return['num_positive'] + $return['num_negative']) * 100), 1);
		}
		// if no positive, but negative ratings exist
		elseif ($return['num_negative'] > 0) {
			$return['karma_rating'] = 0;
		}
		// if only neutral or no ratings exist
		else {
			$return['karma_rating'] = 100;
		}
		
		unset($row);
		return($return);
	}

	/**
	 * Returns all the "karma" ratings against a player
	 *
	 * @var $user_id player to get ratings for
	 * @return mixed array of a player's karma ratings
	 */
	function getPlayerKarmaRatings($user_id){
		$ratings = array();

		$xoops_user_table = $this->db->prefix('users');
		$comp_rating_table = $this->db->prefix('comp_rating');

		// Get the individual comments and ratings
		$sql = "SELECT $xoops_user_table.uname AS rater_name, $comp_rating_table.*, user_table.uname AS user_name " .
			"FROM $xoops_user_table, $comp_rating_table, " .
				"(SELECT uname FROM $xoops_user_table WHERE $xoops_user_table.uid = $user_id ) AS user_table " .
			"WHERE $comp_rating_table.rated_id = $user_id " .
				"AND $comp_rating_table.rater_id = $xoops_user_table.uid " .
			"ORDER BY rating_date DESC";

		$result = $this->db->query($sql);

		$ratings = array();

		while( $row = $this->db->fetchArray($result) ){
			$ratings[] = $row;
		}

		unset($result);

		return $ratings;
	}

	/**
	 * Returns the challenges where the user has not entered a rating for the opposing player.
	 *
	 * @var $user_id player who wishes to rate another player
	 * @return mixed array of challenge and player information
	 */
	function getPlayerUnenteredRatings($user_id){

		$challs = array();

		$comp_rating_table = $this->db->prefix('comp_rating');
		$comp_challenge_table = $this->db->prefix('comp_challenges');
		$xoops_user_table = $this->db->prefix('users');

		// Get any completed challenges without rating by this player
		$sql = "SELECT $comp_challenge_table.challenge_id, $comp_challenge_table.challenger_id, " .
					"$comp_challenge_table.challenged_id, user_ratings.rating_id, " .
					"challenger.uname AS challenger_name, challenged.uname AS challenged_name " .
				"FROM " .
					"(SELECT uname, challenge_id FROM $comp_challenge_table, $xoops_user_table " .
						"WHERE $comp_challenge_table.challenger_id = $xoops_user_table.uid) AS challenger, " .
					"(SELECT uname, challenge_id FROM $comp_challenge_table, $xoops_user_table " .
						"WHERE $comp_challenge_table.challenged_id = $xoops_user_table.uid) AS challenged, " .
					"$comp_challenge_table " .
					"LEFT JOIN (SELECT challenge_id, rating_id FROM $comp_rating_table WHERE rater_id = $user_id) AS user_ratings " .
						"ON $comp_challenge_table.challenge_id = user_ratings.challenge_id " .
				"WHERE $comp_challenge_table.chall_status > 1 " .
					"AND challenged.challenge_id = challenger.challenge_id " .
					"AND $comp_challenge_table.challenge_id = challenged.challenge_id " .
					"AND ($comp_challenge_table.challenger_id = $user_id OR $comp_challenge_table.challenged_id = $user_id) " .
				"ORDER BY $comp_challenge_table.chall_date";

		$result = $this->db->query($sql);

		while( $row = $this->db->fetchArray($result) ){
			if( !isset($row['rating_id']) ){
				$challs[] = $row;
			}
		}

		return $challs;
	}
	
	/**
	 * Returns some recent posts from a player
	 *
	 * @var $user_id player to get posts for
	 * @var $number number of posts to get
	 * @return mixed array of a player's recent posts
	 */
	function getRecentPosts($user_id, $post_number){
	
		$posts = array();

		$xoops_posts_table = $this->db->prefix('bb_posts');

		$sql = "SELECT post_id, pid, topic_id, forum_id, post_time, uid, subject " .
			"FROM " . $xoops_posts_table .
			" WHERE uid = " . $user_id .
			" ORDER BY post_time DESC LIMIT " . $post_number;
			
		$result = $this->db->query($sql);
		
		while( $row = $this->db->fetchArray($result) ){
			$posts[] = $row;
		}
		
		unset($result);

		return $posts;
	}
	
	/**
	 * Returns true if player is top player
	 *
	 * @var $user_id player to get posts for
	 * @var $lid competition id to check
	 * @return boolean true if top player
	 */
	
	function checkTopPlayer($user_id, $lid){
			
		$comp_user_table = $this->db->prefix('comp_user_local');
	
		$sql = "SELECT xoops_user_id " .
			"FROM " . $comp_user_table .
			" WHERE comp_id = " . $lid .
			" ORDER BY rating DESC LIMIT 1 ";

		$result = $this->db->query($sql);
		
		while( $row = $this->db->fetchArray($result) ){
			$return[] = $row;
		}

		if($user_id == $return[0]['xoops_user_id']){
			return true;
		} 
		
		return false;
	
	}

}

?>